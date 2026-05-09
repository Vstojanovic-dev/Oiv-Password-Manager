<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Crypto.php';
require_once __DIR__ . '/../../src/PasswordPolicy.php';
require_once __DIR__ . '/../../src/models/User.php';
require_once __DIR__ . '/../../src/models/Account.php';
require_once __DIR__ . '/../../src/models/LoginAttempt.php';
require_once __DIR__ . '/../../src/models/RecoveryCode.php';
require_once __DIR__ . '/../../src/models/RecoveryAttempt.php';
require_once __DIR__ . '/../../src/models/SecurityEvent.php';
require_once __DIR__ . '/../../src/models/UserSession.php';
require_once __DIR__ . '/../../src/middleware/Auth.php';
require_once __DIR__ . '/../../utils/Response.php';
require_once __DIR__ . '/../../config/app.php';

class AuthController
{
    private User $userModel;
    private LoginAttempt $loginAttemptModel;
    private RecoveryCode $recoveryCodeModel;
    private RecoveryAttempt $recoveryAttemptModel;
    private SecurityEvent $securityEventModel;
    private UserSession $userSessionModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->loginAttemptModel = new LoginAttempt();
        $this->recoveryCodeModel = new RecoveryCode();
        $this->recoveryAttemptModel = new RecoveryAttempt();
        $this->securityEventModel = new SecurityEvent();
        $this->userSessionModel = new UserSession();
    }

    public function register(): void
    {
        $body = $this->parseJsonBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        $errors = $this->validateRegistration($username, $password);
        if (!empty($errors)) {
            Response::error(implode(' ', $errors), 422);
        }
        $passwordPolicy = PasswordPolicy::evaluateMaster($password, null, $username);
        if (!$passwordPolicy['allowed']) {
            Response::error(implode(' ', $passwordPolicy['errors']), 422);
        }
        if ($this->userModel->findByUsername($username) !== null) {
            Response::error('Username is already taken.', 409);
        }

        $kdfSalt = Crypto::newSalt();
        $masterKey = Crypto::deriveMasterKey($password, $kdfSalt);
        $vaultKey = Crypto::newVaultKey();
        $hash = Crypto::hashPassword($password);
        $wrappedVaultKey = Crypto::wrapVaultKey($vaultKey, $masterKey);
        $newId = $this->userModel->create($username, $hash, $kdfSalt, $wrappedVaultKey, null);

        $user = $this->userModel->findById($newId);
        if ($user === null) {
            Response::error('Registration failed.', 500);
        }

        $this->setSessionFromVaultKey($user, $vaultKey);
        sodium_memzero($masterKey);
        sodium_memzero($vaultKey);
        $this->audit((int) $user['id'], $user['username'], 'register_success');

        Response::success([
            'message' => 'Registered.',
            'username' => $user['username'],
            'password_strength' => $passwordPolicy['strength'],
            'password_warnings' => $passwordPolicy['warnings'],
            'csrf_token' => Auth::csrfToken(),
        ], 201);
    }

    public function login(): void
    {
        $body = $this->parseJsonBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            Response::error('Username and password are required.', 422);
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user = $this->userModel->findByUsername($username);
        if ($this->loginAttemptModel->tooMany($username, $ipAddress)) {
            $this->audit($user !== null ? (int) $user['id'] : null, $username, 'login_rate_limited');
            Response::error('Too many failed login attempts. Try again later.', 429);
        }

        if ($user === null || !Crypto::verifyPassword($password, $user['master_password_hash'])) {
            $this->loginAttemptModel->recordFailure($username, $ipAddress);
            $this->audit($user !== null ? (int) $user['id'] : null, $username, 'login_failed');
            Response::error('Invalid username or password.', 401);
        }

        $vaultKey = $this->unlockOrMigrateVault($user, $password);
        $this->loginAttemptModel->clear($username, $ipAddress);
        $this->setSessionFromVaultKey($user, $vaultKey);
        sodium_memzero($vaultKey);
        $this->audit((int) $user['id'], $user['username'], 'login_success');

        Response::success([
            'message' => 'Login successful.',
            'username' => $user['username'],
            'recovery_codes_remaining' => $this->recoveryCodeModel->countUnused((int) $user['id']),
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function changePassword(): void
    {
        $userId = Auth::requireAuth();
        $body = $this->parseJsonBody();

        $current = $body['current_password'] ?? '';
        $new = $body['new_password'] ?? '';

        if (!is_string($current) || !is_string($new) || $current === '' || $new === '') {
            Response::error('current_password and new_password are required.', 422);
        }
        $user = $this->userModel->findById($userId);
        if ($user === null) {
            Response::error('User not found.', 404);
        }
        $passwordPolicy = PasswordPolicy::evaluateMaster($new, $current, $user['username']);
        if (!$passwordPolicy['allowed']) {
            Response::error(implode(' ', $passwordPolicy['errors']), 422);
        }
        if (!Crypto::verifyPassword($current, $user['master_password_hash'])) {
            Response::error('Current password is incorrect.', 401);
        }

        $vaultKey = Auth::vaultKey();
        $newSalt = Crypto::newSalt();
        $newMasterKey = Crypto::deriveMasterKey($new, $newSalt);
        $newHash = Crypto::hashPassword($new);
        $newWrappedVaultKey = Crypto::wrapVaultKey($vaultKey, $newMasterKey);

        $newSessionVersion = $this->userModel->updateMasterPasswordWrapAndInvalidateSessions($userId, $newHash, $newSalt, $newWrappedVaultKey);

        session_regenerate_id(true);
        $_SESSION['vault_key'] = base64_encode($vaultKey);
        $_SESSION['legacy_v1_key'] = base64_encode(Crypto::legacyKeyFromPasswordHash($newHash));
        $_SESSION['session_version'] = $newSessionVersion;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->userSessionModel->upsertCurrent($userId, $newSessionVersion);
        sodium_memzero($newMasterKey);
        sodium_memzero($vaultKey);
        $this->audit($userId, $user['username'], 'password_changed', ['sessions_invalidated' => true]);

        Response::success([
            'message' => 'Password updated.',
            'password_strength' => $passwordPolicy['strength'],
            'password_warnings' => $passwordPolicy['warnings'],
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function recoveryCodes(): void
    {
        $userId = Auth::requireAuth();
        $body = $this->parseJsonBody();
        $currentPassword = $body['current_password'] ?? '';

        if (!is_string($currentPassword) || $currentPassword === '') {
            Response::error('current_password is required.', 422);
        }

        $user = $this->userModel->findById($userId);
        if ($user === null || !Crypto::verifyPassword($currentPassword, $user['master_password_hash'])) {
            Response::error('Current password is incorrect.', 401);
        }

        $vaultKey = Auth::vaultKey();
        $codes = [];
        $db = Database::getInstance();

        try {
            $db->beginTransaction();
            $this->recoveryCodeModel->deleteUnusedForUser($userId);

            for ($i = 0; $i < 8; $i++) {
                $code = Crypto::generateRecoveryCode();
                $salt = Crypto::newSalt();
                $recoveryKey = Crypto::deriveRecoveryKey($code, $salt);
                $this->recoveryCodeModel->create(
                    $userId,
                    $this->recoveryCodeHash($code),
                    $salt,
                    Crypto::wrapVaultKey($vaultKey, $recoveryKey)
                );
                sodium_memzero($recoveryKey);
                $codes[] = $code;
            }

            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Could not generate recovery codes.', 500);
        } finally {
            sodium_memzero($vaultKey);
        }
        $this->audit($userId, $user['username'], 'recovery_codes_generated', ['recovery_codes_count' => count($codes)]);

        Response::success([
            'message' => 'Recovery codes generated. Store them now; they will not be shown again.',
            'codes' => $codes,
        ], 201);
    }

    public function forgotVerify(): void
    {
        $body = $this->parseJsonBody();
        $username = trim($body['username'] ?? '');
        $code = $body['recovery_code'] ?? '';

        if ($username === '' || !is_string($code) || $code === '') {
            Response::error('username and recovery_code are required.', 422);
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($this->recoveryAttemptModel->tooMany($username, $ipAddress, 'verify')) {
            $this->audit(null, $username, 'recovery_verify_rate_limited');
            Response::error('Too many recovery attempts. Try again later.', 429);
        }

        $user = $this->userModel->findByUsername($username);
        if ($user === null) {
            $this->recoveryAttemptModel->recordFailure($username, $ipAddress, 'verify');
            $this->audit(null, $username, 'recovery_verify_failed');
            Response::error('Invalid username or recovery code.', 401);
        }

        $row = $this->recoveryCodeModel->findUnusedByUserAndHash((int) $user['id'], $this->recoveryCodeHash($code));
        if ($row === null) {
            $this->recoveryAttemptModel->recordFailure($username, $ipAddress, 'verify');
            $this->audit((int) $user['id'], $user['username'], 'recovery_verify_failed');
            Response::error('Invalid username or recovery code.', 401);
        }

        $recoveryKey = Crypto::deriveRecoveryKey($code, $row['recovery_salt']);
        $vaultKey = Crypto::unwrapVaultKey($row['encrypted_vault_key'], $recoveryKey);
        sodium_memzero($recoveryKey);

        if ($vaultKey === null) {
            $this->recoveryAttemptModel->recordFailure($username, $ipAddress, 'verify');
            $this->audit((int) $user['id'], $user['username'], 'recovery_verify_failed');
            Response::error('Invalid username or recovery code.', 401);
        }

        $resetToken = bin2hex(random_bytes(32));
        $resetTokenHash = $this->resetTokenHash($resetToken);
        $resetKey = Crypto::resetTokenKey($resetToken);
        $resetWrappedVaultKey = Crypto::wrapVaultKey($vaultKey, $resetKey);
        $expiresAt = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
        $this->userModel->setRecoveryResetToken(
            (int) $user['id'],
            $resetTokenHash,
            $expiresAt,
            (int) $row['id'],
            $resetWrappedVaultKey
        );
        sodium_memzero($resetKey);
        sodium_memzero($vaultKey);
        $this->recoveryAttemptModel->clear($username, $ipAddress, 'verify');
        $this->audit((int) $user['id'], $user['username'], 'recovery_verify_success');

        Response::success([
            'message' => 'Recovery code accepted. Reset token expires in 15 minutes.',
            'reset_token' => $resetToken,
            'expires_at' => $expiresAt,
        ]);
    }

    public function forgotReset(): void
    {
        $body = $this->parseJsonBody();
        $resetToken = $body['reset_token'] ?? '';
        $newPassword = $body['new_password'] ?? '';

        if (!is_string($resetToken) || $resetToken === '' || !is_string($newPassword) || $newPassword === '') {
            Response::error('reset_token and new_password are required.', 422);
        }
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $resetAttemptKey = $this->resetTokenHash($resetToken);
        if ($this->recoveryAttemptModel->tooMany($resetAttemptKey, $ipAddress, 'reset')) {
            $this->audit(null, null, 'recovery_reset_rate_limited');
            Response::error('Too many recovery attempts. Try again later.', 429);
        }

        $user = $this->userModel->findByRecoveryResetToken($resetAttemptKey);
        if ($user === null) {
            $this->recoveryAttemptModel->recordFailure($resetAttemptKey, $ipAddress, 'reset');
            Response::error('Invalid or expired reset token.', 401);
        }
        $passwordPolicy = PasswordPolicy::evaluateMaster($newPassword, null, $user['username']);
        if (!$passwordPolicy['allowed']) {
            Response::error(implode(' ', $passwordPolicy['errors']), 422);
        }
        if (empty($user['recovery_reset_encrypted_vault_key']) || empty($user['recovery_reset_code_id'])) {
            $this->recoveryAttemptModel->recordFailure($resetAttemptKey, $ipAddress, 'reset');
            Response::error('Invalid or expired reset token.', 401);
        }

        $resetKey = Crypto::resetTokenKey($resetToken);
        $vaultKey = Crypto::unwrapVaultKey($user['recovery_reset_encrypted_vault_key'], $resetKey);
        sodium_memzero($resetKey);
        if ($vaultKey === null) {
            $this->recoveryAttemptModel->recordFailure($resetAttemptKey, $ipAddress, 'reset');
            Response::error('Invalid or expired reset token.', 401);
        }

        $newSalt = Crypto::newSalt();
        $newMasterKey = Crypto::deriveMasterKey($newPassword, $newSalt);
        $newHash = Crypto::hashPassword($newPassword);
        $newWrappedVaultKey = Crypto::wrapVaultKey($vaultKey, $newMasterKey);

        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            $this->userModel->updateMasterPasswordWrapAndInvalidateSessions((int) $user['id'], $newHash, $newSalt, $newWrappedVaultKey);
            $this->recoveryCodeModel->markUsed((int) $user['recovery_reset_code_id'], (int) $user['id']);
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Could not reset password.', 500);
        }

        $freshUser = $this->userModel->findById((int) $user['id']);
        $this->setSessionFromVaultKey($freshUser, $vaultKey);
        sodium_memzero($newMasterKey);
        sodium_memzero($vaultKey);
        $this->recoveryAttemptModel->clear($resetAttemptKey, $ipAddress, 'reset');
        $this->audit((int) $freshUser['id'], $freshUser['username'], 'recovery_reset_success', ['sessions_invalidated' => true]);

        Response::success([
            'message' => 'Password reset. Your vault was preserved.',
            'username' => $freshUser['username'],
            'password_strength' => $passwordPolicy['strength'],
            'password_warnings' => $passwordPolicy['warnings'],
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function logout(): void
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
        $this->audit($userId, $username, 'logout');
        if ($userId !== null) {
            $this->userSessionModel->revokeCurrent($userId);
        }

        $this->destroySession();
        Response::success(['message' => 'Logged out successfully.']);
    }

    public function logoutAll(): void
    {
        $userId = Auth::requireAuth();
        $user = $this->userModel->findById($userId);
        if ($user === null) {
            Response::error('User not found.', 404);
        }

        $this->userModel->incrementSessionVersion($userId);
        $this->userSessionModel->revokeAllForUser($userId);
        $this->audit($userId, $user['username'], 'logout_all');
        $this->destroySession();

        Response::success(['message' => 'All sessions logged out successfully.']);
    }

    public function status(): void
    {
        if (!Auth::sessionIsValid()) {
            Response::success(['authenticated' => false]);
        }

        $timeoutSeconds = ($_SESSION['timeout_minutes'] ?? SESSION_TIMEOUT_MINUTES) * 60;
        $idle = time() - ($_SESSION['last_active'] ?? 0);

        Response::success([
            'authenticated' => true,
            'username' => $_SESSION['username'],
            'idle_seconds' => $idle,
            'timeout_seconds' => $timeoutSeconds,
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function csrf(): void
    {
        Response::success(['csrf_token' => Auth::csrfToken()]);
    }

    public function securityEvents(): void
    {
        $userId = Auth::requireAuth();
        Response::success($this->securityEventModel->findLatestForUser($userId));
    }

    public function sessions(): void
    {
        $userId = Auth::requireAuth();
        Response::success($this->userSessionModel->findAllForUser($userId));
    }

    public function revokeSession(int $sessionId): void
    {
        $userId = Auth::requireAuth();
        if (!$this->userSessionModel->revokeById($sessionId, $userId)) {
            Response::error('Session not found.', 404);
        }
        $this->audit($userId, $_SESSION['username'] ?? null, 'session_revoked', ['session_id' => $sessionId]);
        Response::success(['message' => 'Session revoked.']);
    }

    private function unlockOrMigrateVault(array $user, string $password): string
    {
        if (!empty($user['encrypted_vault_key'])) {
            $masterKey = Crypto::deriveMasterKey($password, $user['kdf_salt']);
            $vaultKey = Crypto::unwrapVaultKey($user['encrypted_vault_key'], $masterKey);
            sodium_memzero($masterKey);

            if ($vaultKey === null) {
                Response::error('Could not unlock vault.', 500);
            }

            return $vaultKey;
        }

        return $this->migrateLegacyVault($user, $password);
    }

    private function migrateLegacyVault(array $user, string $password): string
    {
        $userId = (int) $user['id'];
        $oldKey = Crypto::deriveKey($password, $user['kdf_salt']);
        $legacyKey = Crypto::legacyKeyFromPasswordHash($user['master_password_hash']);
        $vaultKey = Crypto::newVaultKey();
        $masterKey = Crypto::deriveMasterKey($password, $user['kdf_salt']);
        $wrappedVaultKey = Crypto::wrapVaultKey($vaultKey, $masterKey);
        $newHash = Crypto::hashPassword($password);
        $accountModel = new Account();
        $rows = $accountModel->findAllByUser($userId, null);
        $db = Database::getInstance();

        try {
            $db->beginTransaction();
            foreach ($rows as $row) {
                $plain = Crypto::decryptVault($row['encrypted_password'], $oldKey, $legacyKey);
                if ($plain === null) {
                    throw new RuntimeException('Failed to decrypt legacy vault entry.');
                }
                $accountModel->updateEncryptedPassword((int) $row['id'], $userId, Crypto::encryptVault($plain, $vaultKey));
            }
            $this->userModel->updateMasterPasswordWrap($userId, $newHash, $user['kdf_salt'], $wrappedVaultKey);
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Could not migrate legacy vault.', 500);
        } finally {
            sodium_memzero($oldKey);
            sodium_memzero($masterKey);
        }

        return $vaultKey;
    }

    private function setSessionFromVaultKey(array $user, string $vaultKey): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['vault_key'] = base64_encode($vaultKey);
        $_SESSION['legacy_v1_key'] = base64_encode(Crypto::legacyKeyFromPasswordHash($user['master_password_hash']));
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
        $_SESSION['logged_in_at'] = time();
        $_SESSION['last_active'] = time();
        $_SESSION['timeout_minutes'] = $user['session_timeout_minutes'] ?? SESSION_TIMEOUT_MINUTES;
        Auth::csrfToken();
        $this->userSessionModel->upsertCurrent((int) $user['id'], (int) ($user['session_version'] ?? 1));
    }

    private function validateRegistration(string $username, string $password): array
    {
        $errors = [];
        $len = strlen($username);
        if ($len < 3 || $len > 64) {
            $errors[] = 'Username must be between 3 and 64 characters.';
        }
        return $errors;
    }

    private function recoveryCodeHash(string $code): string
    {
        return hash_hmac('sha256', Crypto::normalizeRecoveryCode($code), APP_SECRET);
    }

    private function resetTokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, APP_SECRET);
    }

    private function audit(?int $userId, ?string $username, string $eventType, array $metadata = []): void
    {
        try {
            $this->securityEventModel->record($userId, $username, $eventType, $metadata);
        } catch (Throwable) {
            // Audit logging must not break the primary auth flow.
        }
    }

    private function destroySession(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
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
}
