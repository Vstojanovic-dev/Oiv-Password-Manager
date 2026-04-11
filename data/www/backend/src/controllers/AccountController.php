<?php

require_once __DIR__ . '/../../src/models/Account.php';
require_once __DIR__ . '/../../src/middleware/Auth.php';
require_once __DIR__ . '/../../utils/Response.php';

/**
 * AccountController
 *
 * Handles all account CRUD endpoints. Every method calls Auth::requireAuth()
 * first, so all routes are protected — no session, no access.
 *
 * Password encryption strategy:
 *   Vault passwords must be retrievable, so they are AES-256-CBC encrypted
 *   (not hashed). The encryption key is derived from the master password hash
 *   stored in the session — this way the key never touches the database.
 *
 *   encrypt() / decrypt() are private helpers used by every endpoint.
 */
class AccountController
{
    private Account $accountModel;

    public function __construct()
    {
        $this->accountModel = new Account();
    }

    // -------------------------------------------------------------------------
    // GET /accounts          → list all (optional ?search=)
    // -------------------------------------------------------------------------
    public function index(): void
    {
        $userId = Auth::requireAuth();
        $search = $_GET['search'] ?? null;

        $accounts = $this->accountModel->findAllByUser($userId, $search);

        // Decrypt passwords before sending
        $accounts = array_map(function (array $account): array {
            $account['password'] = $this->decrypt($account['encrypted_password']);
            unset($account['encrypted_password']);
            return $account;
        }, $accounts);

        Response::success($accounts);
    }

    // -------------------------------------------------------------------------
    // GET /accounts/{id}     → single account
    // -------------------------------------------------------------------------
    public function show(int $id): void
    {
        $userId  = Auth::requireAuth();
        $account = $this->accountModel->findByIdAndUser($id, $userId);

        if ($account === null) {
            Response::error('Account not found.', 404);
        }

        $account['password'] = $this->decrypt($account['encrypted_password']);
        unset($account['encrypted_password']);

        Response::success($account);
    }

    // -------------------------------------------------------------------------
    // POST /accounts         → create
    // Body: { "site_name", "site_url"?, "username", "password", "notes"? }
    // -------------------------------------------------------------------------
    public function store(): void
    {
        $userId = Auth::requireAuth();
        $body   = $this->parseJsonBody();

        $errors = $this->validate($body, ['site_name', 'username', 'password']);
        if (!empty($errors)) {
            Response::error(implode(' ', $errors), 422);
        }

        $newId = $this->accountModel->create(
            $userId,
            trim($body['site_name']),
            isset($body['site_url'])  ? trim($body['site_url'])  : null,
            trim($body['username']),
            $this->encrypt($body['password']),
            isset($body['notes'])     ? trim($body['notes'])     : null
        );

        $account = $this->accountModel->findByIdAndUser($newId, $userId);
        $account['password'] = $this->decrypt($account['encrypted_password']);
        unset($account['encrypted_password']);

        Response::success($account, 201);
    }

    // -------------------------------------------------------------------------
    // PUT /accounts/{id}     → update
    // Body: { "site_name", "site_url"?, "username", "password", "notes"? }
    // -------------------------------------------------------------------------
    public function update(int $id): void
    {
        $userId = Auth::requireAuth();
        $body   = $this->parseJsonBody();

        // Confirm the account exists and belongs to this user before updating
        $existing = $this->accountModel->findByIdAndUser($id, $userId);
        if ($existing === null) {
            Response::error('Account not found.', 404);
        }

        $errors = $this->validate($body, ['site_name', 'username', 'password']);
        if (!empty($errors)) {
            Response::error(implode(' ', $errors), 422);
        }

        $this->accountModel->update(
            $id,
            $userId,
            trim($body['site_name']),
            isset($body['site_url'])  ? trim($body['site_url'])  : null,
            trim($body['username']),
            $this->encrypt($body['password']),
            isset($body['notes'])     ? trim($body['notes'])     : null
        );

        $account = $this->accountModel->findByIdAndUser($id, $userId);
        $account['password'] = $this->decrypt($account['encrypted_password']);
        unset($account['encrypted_password']);

        Response::success($account);
    }

    // -------------------------------------------------------------------------
    // DELETE /accounts/{id}  → delete
    // -------------------------------------------------------------------------
    public function destroy(int $id): void
    {
        $userId = Auth::requireAuth();

        $existing = $this->accountModel->findByIdAndUser($id, $userId);
        if ($existing === null) {
            Response::error('Account not found.', 404);
        }

        $this->accountModel->delete($id, $userId);

        Response::success(['message' => 'Account deleted successfully.']);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Encrypt a plaintext password using AES-256-CBC.
     *
     * The encryption key is derived from the session — specifically a SHA-256
     * hash of the stored master_password_hash. This means:
     *   - The key is never stored in the database.
     *   - The key is only available during an authenticated session.
     *   - Changing the master password would invalidate all stored entries
     *     (acceptable at this project scope; a re-encryption step would be
     *     needed for a production app).
     *
     * Output format: base64( iv + ciphertext )
     */
    private function encrypt(string $plaintext): string
    {
        $key    = $this->derivedKey();
        $iv     = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a value produced by encrypt().
     * Returns an empty string if decryption fails (e.g. corrupted data).
     */
    private function decrypt(string $encoded): string
    {
        $key  = $this->derivedKey();
        $raw  = base64_decode($encoded);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');

        if (strlen($raw) <= $ivLength) {
            return '';
        }

        $iv         = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);
        $plain      = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    /**
     * Derive a 32-byte encryption key from the current session.
     * Using the master_password_hash (set during login) as the source
     * means the key is tied to the user's master password without storing it.
     */
    private function derivedKey(): string
    {
        $source = $_SESSION['master_password_hash'] ?? session_id();
        return hash('sha256', $source, true); // raw binary output = 32 bytes
    }

    /**
     * Decode the JSON request body. Exits with 400 if malformed.
     */
    private function parseJsonBody(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            Response::error('Request body must be valid JSON.', 400);
        }

        return $data;
    }

    /**
     * Validate that required fields are present and non-empty.
     * Returns an array of error strings (empty = valid).
     */
    private function validate(array $body, array $required): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (!isset($body[$field]) || trim((string) $body[$field]) === '') {
                $errors[] = "Field '{$field}' is required.";
            }
        }
        return $errors;
    }
}