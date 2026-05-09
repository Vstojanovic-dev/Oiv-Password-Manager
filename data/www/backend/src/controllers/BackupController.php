<?php

require_once __DIR__ . '/../../src/Crypto.php';
require_once __DIR__ . '/../../src/models/Account.php';
require_once __DIR__ . '/../../src/models/Backup.php';
require_once __DIR__ . '/../../src/models/SecurityEvent.php';
require_once __DIR__ . '/../../src/middleware/Auth.php';
require_once __DIR__ . '/../../utils/Response.php';
require_once __DIR__ . '/../../config/app.php';

class BackupController
{
    private Account $accountModel;
    private Backup $backupModel;
    private SecurityEvent $securityEventModel;
    private string $backupDir;

    public function __construct()
    {
        $this->accountModel = new Account();
        $this->backupModel = new Backup();
        $this->securityEventModel = new SecurityEvent();
        $this->backupDir = rtrim(BACKUP_DIR, '/\\');

        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0750, true)) {
            Response::error('Backup storage is not writable.', 500);
        }
        if (!is_writable($this->backupDir)) {
            Response::error('Backup storage is not writable.', 500);
        }
    }

    public function create(): void
    {
        $userId = Auth::requireAuth();
        $body = $this->parseJsonBody();
        $backupPassword = $body['backup_password'] ?? '';

        if (!is_string($backupPassword) || strlen($backupPassword) < 8) {
            Response::error('backup_password is required and must be at least 8 characters.', 422);
        }

        $key = Auth::vaultKey();
        $legacyKey = Auth::legacyVaultKey();
        $accounts = $this->accountModel->findAllByUser($userId);
        $exported = [];

        foreach ($accounts as $row) {
            $plain = Crypto::decryptVault($row['encrypted_password'], $key, $legacyKey);
            if ($plain === null) {
                Response::error('Could not decrypt all vault entries for backup.', 500);
            }

            $exported[] = [
                'site_name' => $row['site_name'],
                'site_url' => $row['site_url'],
                'username' => $row['username'],
                'password' => $plain,
                'favorite' => (bool) ($row['favorite'] ?? false),
                'category' => $row['category'],
                'last_used_at' => $row['last_used_at'],
                'password_updated_at' => $row['password_updated_at'],
                'notes' => $row['notes'],
            ];
        }

        $filename = sprintf('backup_%s_%s.json', $userId, date('Ymd_His'));
        $plainPayload = json_encode([
            'exported_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'username' => $_SESSION['username'],
            'filename' => $filename,
            'accounts' => $exported,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($plainPayload === false) {
            Response::error('Failed to encode backup payload.', 500);
        }

        $encryptedPayload = Crypto::encryptBackupJson($plainPayload, $backupPassword);
        $filepath = $this->backupDir . '/' . $filename;

        if (@file_put_contents($filepath, json_encode($encryptedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            Response::error('Failed to write backup file.', 500);
        }

        $this->backupModel->create($userId, $filename);
        $this->audit($userId, 'backup_created', [
            'filename' => $filename,
            'count' => count($exported),
        ]);

        Response::success([
            'message' => 'Encrypted backup created successfully.',
            'filename' => $filename,
            'count' => count($exported),
        ], 201);
    }

    public function restore(): void
    {
        $userId = Auth::requireAuth();
        $backupPassword = $_POST['backup_password'] ?? '';

        if (!is_string($backupPassword) || strlen($backupPassword) < 8) {
            $this->failRestore($userId, 'backup_password is required and must be at least 8 characters.', 422, 'invalid_backup_password_input');
        }
        if (empty($_FILES['backup_file'])) {
            $this->failRestore($userId, 'No file uploaded. Send the backup as "backup_file".', 422, 'missing_file');
        }

        $file = $_FILES['backup_file'];
        $this->validateUploadedBackupFile($file, $userId);
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->failRestore($userId, 'File upload failed with error code: ' . $file['error'], 422, 'upload_error');
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) {
            $this->failRestore($userId, 'Could not read uploaded file.', 500, 'read_failed');
        }
        if (strlen($raw) > BACKUP_MAX_BYTES) {
            $this->failRestore($userId, 'Backup file is too large.', 413, 'file_too_large_after_read');
        }

        $encryptedPayload = json_decode($raw, true);
        if (!is_array($encryptedPayload)) {
            $this->failRestore($userId, 'Invalid backup file format.', 422, 'invalid_file_format');
        }

        $plainJson = Crypto::decryptBackupJson($encryptedPayload, $backupPassword);
        if ($plainJson === null) {
            $this->failRestore($userId, 'Invalid backup password or corrupt backup file.', 422, 'decrypt_failed');
        }

        $payload = json_decode($plainJson, true);
        if (!is_array($payload) || !isset($payload['accounts']) || !is_array($payload['accounts'])) {
            $this->failRestore($userId, 'Invalid decrypted backup payload.', 422, 'invalid_decrypted_payload');
        }
        if (count($payload['accounts']) > BACKUP_MAX_ACCOUNTS) {
            $this->failRestore($userId, 'Backup contains too many entries.', 413, 'too_many_accounts');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($payload['accounts'] as $index => $entry) {
            $validation = $this->validateEntry($entry);
            if (!empty($validation)) {
                $skipped++;
                $errors[] = ['index' => $index, 'errors' => $validation];
                continue;
            }

            $this->accountModel->create(
                $userId,
                trim((string) $entry['site_name']),
                $this->normalizeSiteUrl($entry['site_url'] ?? null),
                trim((string) $entry['username']),
                Crypto::encryptVault((string) $entry['password'], Auth::vaultKey()),
                $this->normalizeBoolean($entry['favorite'] ?? false),
                $this->nullableTrim($entry['category'] ?? null),
                $this->nullableTrim($entry['notes'] ?? null),
                $this->normalizeDateTime($entry['last_used_at'] ?? null),
                $this->normalizeDateTime($entry['password_updated_at'] ?? null)
            );
            $imported++;
        }

        if (!empty($payload['filename']) && is_string($payload['filename'])) {
            $this->backupModel->markRestored($userId, $payload['filename']);
        }
        $this->audit($userId, 'backup_restore_success', [
            'imported' => $imported,
            'skipped' => $skipped,
        ]);

        Response::success([
            'message' => 'Restore completed.',
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    public function history(): void
    {
        $userId = Auth::requireAuth();
        Response::success($this->backupModel->findAllByUser($userId));
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

    private function validateEntry(mixed $entry): array
    {
        if (!is_array($entry)) {
            return ['Entry must be an object.'];
        }

        $errors = [];
        $siteName = trim((string) ($entry['site_name'] ?? ''));
        $username = trim((string) ($entry['username'] ?? ''));
        $passwordExists = array_key_exists('password', $entry);
        $siteUrl = $this->normalizeSiteUrl($entry['site_url'] ?? null);

        if ($siteName === '') {
            $errors[] = 'site_name is required.';
        }
        if ($username === '') {
            $errors[] = 'username is required.';
        }
        if (!$passwordExists) {
            $errors[] = 'password is required.';
        }
        if (strlen($siteName) > 128) {
            $errors[] = 'site_name must be 128 characters or less.';
        }
        if (strlen($username) > 128) {
            $errors[] = 'username must be 128 characters or less.';
        }
        if ($siteUrl !== null && (strlen($siteUrl) > 512 || !filter_var($siteUrl, FILTER_VALIDATE_URL))) {
            $errors[] = 'site_url must be a valid URL with 512 characters or less.';
        }
        if (array_key_exists('favorite', $entry) && $this->normalizeBoolean($entry['favorite'], true) === null) {
            $errors[] = 'favorite must be a boolean.';
        }
        $category = $this->nullableTrim($entry['category'] ?? null);
        if ($category !== null && strlen($category) > 64) {
            $errors[] = 'category must be 64 characters or less.';
        }
        if (array_key_exists('last_used_at', $entry) && $this->normalizeDateTime($entry['last_used_at'], true) === null && $entry['last_used_at'] !== null && $entry['last_used_at'] !== '') {
            $errors[] = 'last_used_at must be a MySQL DATETIME value.';
        }
        if (array_key_exists('password_updated_at', $entry) && $this->normalizeDateTime($entry['password_updated_at'], true) === null && $entry['password_updated_at'] !== null && $entry['password_updated_at'] !== '') {
            $errors[] = 'password_updated_at must be a MySQL DATETIME value.';
        }

        return $errors;
    }

    private function validateUploadedBackupFile(array $file, int $userId): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_FORM_SIZE) {
            $this->failRestore($userId, 'Backup file is too large.', 413, 'file_too_large');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            $this->failRestore($userId, 'Backup file is empty.', 422, 'empty_file');
        }
        if ($size > BACKUP_MAX_BYTES) {
            $this->failRestore($userId, 'Backup file is too large.', 413, 'file_too_large');
        }

        $name = (string) ($file['name'] ?? '');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
            $this->failRestore($userId, 'Backup file must have a .json extension.', 422, 'invalid_extension');
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type((string) $file['tmp_name']);
            $allowed = ['application/json', 'text/plain', 'application/octet-stream', 'text/x-json'];
            if (is_string($mime) && !in_array(strtolower($mime), $allowed, true)) {
                $this->failRestore($userId, 'Backup file must be a JSON file.', 422, 'invalid_mime');
            }
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeSiteUrl(mixed $value): ?string
    {
        $url = $this->nullableTrim($value);
        if ($url === null) {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function normalizeBoolean(mixed $value, bool $nullable = false): ?bool
    {
        if ($nullable && ($value === null || $value === '')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function normalizeDateTime(mixed $value, bool $nullable = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function failRestore(int $userId, string $message, int $status, string $reason): void
    {
        $this->audit($userId, 'backup_restore_failed', ['reason' => $reason]);
        Response::error($message, $status);
    }

    private function audit(int $userId, string $eventType, array $metadata = []): void
    {
        try {
            $username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
            $this->securityEventModel->record($userId, $username, $eventType, $metadata);
        } catch (Throwable) {
            // Audit logging must not break backup operations.
        }
    }
}
