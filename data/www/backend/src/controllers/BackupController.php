<?php

require_once __DIR__ . '/../../src/models/Account.php';
require_once __DIR__ . '/../../src/models/Backup.php';
require_once __DIR__ . '/../../src/middleware/Auth.php';
require_once __DIR__ . '/../../utils/Response.php';

/**
 * BackupController
 *
 * POST /backup         – export all accounts to a JSON file, log it in DB
 * POST /restore        – accept a JSON backup file and re-import accounts
 * GET  /backup/history – list previous backups for the current user
 *
 * Backup files are stored inside the container at /var/www/html/backend/backups/
 * which maps to data/www/backend/backups/ on the host — so they survive
 * container restarts without any extra volume config.
 *
 * File format:
 * {
 *   "exported_at": "2026-01-01T12:00:00+00:00",
 *   "username": "testuser",
 *   "accounts": [
 *     { "site_name": "...", "site_url": "...", "username": "...",
 *       "password": "...(plaintext, re-encrypted on restore)...", "notes": "..." }
 *   ]
 * }
 */
class BackupController
{
    private Account $accountModel;
    private Backup  $backupModel;

    // Directory where backup files are written (relative to this file)
    private string $backupDir;

    public function __construct()
    {
        $this->accountModel = new Account();
        $this->backupModel  = new Backup();
        $this->backupDir    = __DIR__ . '/../../backups';

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    // -------------------------------------------------------------------------
    // POST /backup  –  create a backup
    // -------------------------------------------------------------------------
    public function create(): void
    {
        $userId   = Auth::requireAuth();
        $username = $_SESSION['username'];

        $accounts = $this->accountModel->findAllByUser($userId);

        // Decrypt passwords so the backup file contains readable data that
        // can be re-imported even if the master password changes
        $exported = array_map(function (array $row) use ($userId): array {
            return [
                'site_name' => $row['site_name'],
                'site_url'  => $row['site_url'],
                'username'  => $row['username'],
                'password'  => $this->decrypt($row['encrypted_password']),
                'notes'     => $row['notes'],
            ];
        }, $accounts);

        $payload = [
            'exported_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'username'    => $username,
            'accounts'    => $exported,
        ];

        $filename = sprintf('backup_%s_%s.json', $userId, date('Ymd_His'));
        $filepath = $this->backupDir . '/' . $filename;

        if (file_put_contents($filepath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            Response::error('Failed to write backup file.', 500);
        }

        $this->backupModel->create($userId, $filename);

        Response::success([
            'message'  => 'Backup created successfully.',
            'filename' => $filename,
            'count'    => count($exported),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // POST /restore  –  restore from an uploaded JSON backup
    // Expects multipart/form-data with a field named "backup_file"
    // -------------------------------------------------------------------------
    public function restore(): void
    {
        $userId = Auth::requireAuth();

        if (empty($_FILES['backup_file'])) {
            Response::error('No file uploaded. Send the backup as "backup_file".', 422);
        }

        $file = $_FILES['backup_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload failed with error code: ' . $file['error'], 422);
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) {
            Response::error('Could not read uploaded file.', 500);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['accounts']) || !is_array($payload['accounts'])) {
            Response::error('Invalid backup file format.', 422);
        }

        $imported = 0;
        $skipped  = 0;

        foreach ($payload['accounts'] as $entry) {
            // Skip malformed entries rather than aborting the whole restore
            if (empty($entry['site_name']) || empty($entry['username']) || !isset($entry['password'])) {
                $skipped++;
                continue;
            }

            $this->accountModel->create(
                $userId,
                $entry['site_name'],
                $entry['site_url'] ?? null,
                $entry['username'],
                $this->encrypt($entry['password']),
                $entry['notes'] ?? null
            );

            $imported++;
        }

        // Mark the backup record as restored if the filename is recognisable
        if (!empty($payload['filename'])) {
            $this->backupModel->markRestored($userId, $payload['filename']);
        }

        Response::success([
            'message'  => 'Restore completed.',
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /backup/history  –  list past backups
    // -------------------------------------------------------------------------
    public function history(): void
    {
        $userId  = Auth::requireAuth();
        $backups = $this->backupModel->findAllByUser($userId);
        Response::success($backups);
    }

    // =========================================================================
    // Private helpers — mirror AccountController's encrypt/decrypt
    // =========================================================================

    private function encrypt(string $plaintext): string
    {
        $key    = $this->derivedKey();
        $iv     = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $encoded): string
    {
        $key      = $this->derivedKey();
        $raw      = base64_decode($encoded);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');

        if (strlen($raw) <= $ivLength) {
            return '';
        }

        $iv     = substr($raw, 0, $ivLength);
        $cipher = substr($raw, $ivLength);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    private function derivedKey(): string
    {
        $source = $_SESSION['master_password_hash'] ?? session_id();
        return hash('sha256', $source, true);
    }
}