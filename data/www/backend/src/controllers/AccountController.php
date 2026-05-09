<?php

require_once __DIR__ . '/../../src/Crypto.php';
require_once __DIR__ . '/../../src/PasswordPolicy.php';
require_once __DIR__ . '/../../src/models/Account.php';
require_once __DIR__ . '/../../src/models/AccountPasswordHistory.php';
require_once __DIR__ . '/../../src/middleware/Auth.php';
require_once __DIR__ . '/../../utils/Response.php';

class AccountController
{
    private Account $accountModel;
    private AccountPasswordHistory $passwordHistoryModel;

    public function __construct()
    {
        $this->accountModel = new Account();
        $this->passwordHistoryModel = new AccountPasswordHistory();
    }

    public function index(): void
    {
        $userId = Auth::requireAuth();
        $search = $_GET['search'] ?? null;
        $favorite = $this->optionalBoolean($_GET['favorite'] ?? null);
        $category = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
        $accounts = array_map(function (array $account): array {
            return $this->formatAccount($account);
        }, $this->accountModel->findAllByUser($userId, $search, $favorite, $category));

        Response::success($accounts);
    }

    public function trash(): void
    {
        $userId = Auth::requireAuth();
        $accounts = array_map(function (array $account): array {
            return $this->formatAccount($account);
        }, $this->accountModel->findAllByUser($userId, null, null, null, true));

        Response::success($accounts);
    }

    public function show(int $id): void
    {
        $userId = Auth::requireAuth();
        $account = $this->accountModel->findByIdAndUser($id, $userId);

        if ($account === null) {
            Response::error('Account not found.', 404);
        }

        Response::success($this->formatAccount($account));
    }

    public function store(): void
    {
        $userId = Auth::requireAuth();
        $body = $this->parseJsonBody();
        $data = $this->validatedPayload($body);
        $vaultKey = Auth::vaultKey();
        $legacyKey = Auth::legacyVaultKey();
        $passwordAssessment = PasswordPolicy::evaluateVault(
            $data['password'],
            $this->passwordAlreadyUsed($userId, $data['password'], $vaultKey, $legacyKey)
        );

        $newId = $this->accountModel->create(
            $userId,
            $data['site_name'],
            $data['site_url'],
            $data['username'],
            Crypto::encryptVault($data['password'], $vaultKey),
            $data['favorite'],
            $data['category'],
            $data['notes']
        );

        $account = $this->accountModel->findByIdAndUser($newId, $userId);
        $account['password'] = $data['password'];
        $account['password_strength'] = $passwordAssessment['strength'];
        $account['password_warnings'] = $passwordAssessment['warnings'];

        Response::success($this->formatAccount($account), 201);
    }

    public function update(int $id): void
    {
        $userId = Auth::requireAuth();
        $body = $this->parseJsonBody();

        $existing = $this->accountModel->findByIdAndUser($id, $userId);
        if ($existing === null) {
            Response::error('Account not found.', 404);
        }

        $data = $this->validatedPayload($body);
        $vaultKey = Auth::vaultKey();
        $legacyKey = Auth::legacyVaultKey();
        $oldPassword = $this->decryptPasswordOrFail($existing, $vaultKey, $legacyKey);
        $passwordAssessment = PasswordPolicy::evaluateVault(
            $data['password'],
            $this->passwordAlreadyUsed($userId, $data['password'], $vaultKey, $legacyKey, $id)
        );
        if (!hash_equals($oldPassword, $data['password'])) {
            $this->passwordHistoryModel->create($id, $userId, $existing['encrypted_password'], $existing['password_updated_at'] ?? null);
        }

        $this->accountModel->update(
            $id,
            $userId,
            $data['site_name'],
            $data['site_url'],
            $data['username'],
            Crypto::encryptVault($data['password'], $vaultKey),
            $data['favorite'],
            $data['category'],
            $data['notes']
        );

        $account = $this->accountModel->findByIdAndUser($id, $userId);
        $account['password'] = $data['password'];
        $account['password_strength'] = $passwordAssessment['strength'];
        $account['password_warnings'] = $passwordAssessment['warnings'];

        Response::success($this->formatAccount($account));
    }

    public function destroy(int $id): void
    {
        $userId = Auth::requireAuth();

        if ($this->accountModel->findByIdAndUser($id, $userId) === null) {
            Response::error('Account not found.', 404);
        }

        $this->accountModel->delete($id, $userId);
        Response::success(['message' => 'Account moved to trash.']);
    }

    public function restore(int $id): void
    {
        $userId = Auth::requireAuth();
        $account = $this->accountModel->findAnyByIdAndUser($id, $userId);
        if ($account === null || empty($account['deleted_at'])) {
            Response::error('Deleted account not found.', 404);
        }

        $this->accountModel->restore($id, $userId);
        $restored = $this->accountModel->findByIdAndUser($id, $userId);
        Response::success($this->formatAccount($restored));
    }

    public function permanentlyDelete(int $id): void
    {
        $userId = Auth::requireAuth();
        $account = $this->accountModel->findAnyByIdAndUser($id, $userId);
        if ($account === null || empty($account['deleted_at'])) {
            Response::error('Deleted account not found.', 404);
        }

        $this->accountModel->permanentlyDelete($id, $userId);
        Response::success(['message' => 'Account permanently deleted.']);
    }

    public function markUsed(int $id): void
    {
        $userId = Auth::requireAuth();

        if ($this->accountModel->findByIdAndUser($id, $userId) === null) {
            Response::error('Account not found.', 404);
        }

        $this->accountModel->markUsed($id, $userId);
        $account = $this->accountModel->findByIdAndUser($id, $userId);
        Response::success($this->formatAccount($account));
    }

    public function password(int $id): void
    {
        $userId = Auth::requireAuth();
        $account = $this->accountModel->findByIdAndUser($id, $userId);

        if ($account === null) {
            Response::error('Account not found.', 404);
        }

        Response::success([
            'password' => $this->decryptPasswordOrFail($account, Auth::vaultKey(), Auth::legacyVaultKey()),
        ]);
    }

    public function passwordHistory(int $id): void
    {
        $userId = Auth::requireAuth();
        $account = $this->accountModel->findAnyByIdAndUser($id, $userId);
        if ($account === null) {
            Response::error('Account not found.', 404);
        }

        $vaultKey = Auth::vaultKey();
        $legacyKey = Auth::legacyVaultKey();
        $history = array_map(function (array $row) use ($vaultKey, $legacyKey): array {
            $password = Crypto::decryptVault($row['encrypted_password'], $vaultKey, $legacyKey);
            if ($password === null) {
                Response::error('Could not decrypt password history.', 500);
            }

            return [
                'id' => (int) $row['id'],
                'password' => $password,
                'password_updated_at' => $row['password_updated_at'],
                'created_at' => $row['created_at'],
            ];
        }, $this->passwordHistoryModel->findByAccount($id, $userId));

        Response::success($history);
    }

    public function passwordReport(): void
    {
        $userId = Auth::requireAuth();
        $vaultKey = Auth::vaultKey();
        $legacyKey = Auth::legacyVaultKey();
        $accounts = $this->accountModel->findAllByUser($userId);
        $passwordMap = [];
        $weak = [];
        $reused = [];
        $old = [];
        $missingUrl = [];
        $cutoff = new DateTimeImmutable('-90 days');

        foreach ($accounts as $account) {
            $password = $this->decryptPasswordOrFail($account, $vaultKey, $legacyKey);
            $hash = hash('sha256', $password);
            $passwordMap[$hash][] = (int) $account['id'];
            $assessment = PasswordPolicy::evaluateVault($password);
            if ($assessment['strength'] === 'weak' || !empty($assessment['warnings'])) {
                $weak[] = $this->reportAccount($account);
            }
            if (empty($account['site_url'])) {
                $missingUrl[] = $this->reportAccount($account);
            }
            $updated = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $account['password_updated_at']);
            if ($updated !== false && $updated < $cutoff) {
                $old[] = $this->reportAccount($account);
            }
        }

        foreach ($passwordMap as $ids) {
            if (count($ids) > 1) {
                $reused = array_merge($reused, $ids);
            }
        }
        $reused = array_values(array_unique($reused));

        Response::success([
            'total' => count($accounts),
            'weak_count' => count($weak),
            'reused_count' => count($reused),
            'old_password_count' => count($old),
            'missing_url_count' => count($missingUrl),
            'weak' => $weak,
            'reused_account_ids' => $reused,
            'old_passwords' => $old,
            'missing_urls' => $missingUrl,
        ]);
    }

    private function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            Response::error('Request body must be valid JSON.', 400);
        }

        return $data;
    }

    private function validatedPayload(array $body): array
    {
        $siteName = trim((string) ($body['site_name'] ?? ''));
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $siteUrl = $this->normalizeSiteUrl($body['site_url'] ?? null);
        $notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
        $favorite = filter_var($body['favorite'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $category = isset($body['category']) ? trim((string) $body['category']) : null;
        $errors = [];

        if ($siteName === '') {
            $errors[] = "Field 'site_name' is required.";
        }
        if ($username === '') {
            $errors[] = "Field 'username' is required.";
        }
        if ($password === '') {
            $errors[] = "Field 'password' is required.";
        }
        if (strlen($siteName) > 128) {
            $errors[] = 'site_name must be 128 characters or less.';
        }
        if (strlen($username) > 128) {
            $errors[] = 'username must be 128 characters or less.';
        }
        if ($siteUrl !== null && $siteUrl !== '') {
            if (strlen($siteUrl) > 512) {
                $errors[] = 'site_url must be 512 characters or less.';
            }
            if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'site_url must be a valid URL.';
            }
        }
        if ($favorite === null) {
            $errors[] = 'favorite must be a boolean.';
        }
        if ($category !== null && $category !== '' && strlen($category) > 64) {
            $errors[] = 'category must be 64 characters or less.';
        }

        if (!empty($errors)) {
            Response::error(implode(' ', $errors), 422);
        }

        return [
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'username' => $username,
            'password' => $password,
            'favorite' => $favorite ?? false,
            'category' => $category === '' ? null : $category,
            'notes' => $notes === '' ? null : $notes,
        ];
    }

    private function formatAccount(array $account): array
    {
        unset($account['encrypted_password']);
        $account['favorite'] = (bool) ($account['favorite'] ?? false);
        return $account;
    }

    private function normalizeSiteUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function optionalBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function reportAccount(array $account): array
    {
        return [
            'id' => (int) $account['id'],
            'site_name' => $account['site_name'],
            'username' => $account['username'],
            'category' => $account['category'],
            'password_updated_at' => $account['password_updated_at'],
        ];
    }

    private function passwordAlreadyUsed(int $userId, string $password, string $vaultKey, ?string $legacyKey, ?int $excludeId = null): bool
    {
        foreach ($this->accountModel->findAllByUser($userId, null) as $account) {
            if ($excludeId !== null && (int) $account['id'] === $excludeId) {
                continue;
            }

            $plain = $this->decryptPasswordOrFail($account, $vaultKey, $legacyKey);
            if (hash_equals($plain, $password)) {
                return true;
            }
        }

        return false;
    }

    private function decryptPasswordOrFail(array $account, string $vaultKey, ?string $legacyKey): string
    {
        $plain = Crypto::decryptVault($account['encrypted_password'], $vaultKey, $legacyKey);
        if ($plain === null) {
            Response::error('Could not decrypt vault entry.', 500);
        }

        return $plain;
    }
}
