<?php

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/models/UserSession.php';
require_once __DIR__ . '/../../utils/Response.php';

class Auth
{
    /**
     * Start the session with secure cookie parameters.
     * Must be called before any output is sent.
     */
    public static function startSession(): void
    {
        session_set_cookie_params([
            'lifetime' => 0,        // Expires when the browser closes
            'path'     => '/',
            'secure'   => APP_ENV === 'production',
            'httponly' => true,     // Blocks JS access to the session cookie
            'samesite' => 'Strict',
        ]);

        session_start();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            Response::error('Invalid CSRF token.', 403);
        }
    }

    public static function requireAuth(): int
    {
        if (empty($_SESSION['user_id'])) {
            Response::error('Unauthenticated. Please log in.', 401);
        }

        // Enforce inactivity timeout
        $timeoutSeconds = ($_SESSION['timeout_minutes'] ?? SESSION_TIMEOUT_MINUTES) * 60;
        $idle = time() - ($_SESSION['last_active'] ?? 0);

        if ($idle > $timeoutSeconds) {
            session_destroy();
            Response::error('Session timed out. Please log in again.', 401);
        }

        // Slide the timeout window
        $_SESSION['last_active'] = time();

        if (!self::sessionVersionIsCurrent((int) $_SESSION['user_id'])) {
            session_destroy();
            Response::error('Session is no longer valid. Please log in again.', 401);
        }
        if (!self::sessionRecordIsActive((int) $_SESSION['user_id'])) {
            session_destroy();
            Response::error('Session is no longer valid. Please log in again.', 401);
        }

        return (int) $_SESSION['user_id'];
    }

    public static function sessionIsValid(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $timeoutSeconds = ($_SESSION['timeout_minutes'] ?? SESSION_TIMEOUT_MINUTES) * 60;
        $idle = time() - ($_SESSION['last_active'] ?? 0);
        if ($idle > $timeoutSeconds) {
            session_destroy();
            return false;
        }

        if (!self::sessionVersionIsCurrent((int) $_SESSION['user_id'])) {
            session_destroy();
            return false;
        }
        if (!self::sessionRecordIsActive((int) $_SESSION['user_id'])) {
            session_destroy();
            return false;
        }

        $_SESSION['last_active'] = time();
        return true;
    }

    public static function vaultKey(): string
    {
        if (empty($_SESSION['vault_key'])) {
            Response::error('Vault is locked. Please log in again.', 401);
        }

        $key = base64_decode($_SESSION['vault_key'], true);
        if ($key === false) {
            Response::error('Vault is locked. Please log in again.', 401);
        }

        return $key;
    }

    public static function legacyVaultKey(): ?string
    {
        if (empty($_SESSION['legacy_v1_key'])) {
            return null;
        }

        $key = base64_decode($_SESSION['legacy_v1_key'], true);
        return $key === false ? null : $key;
    }

    private static function sessionVersionIsCurrent(int $userId): bool
    {
        if (!isset($_SESSION['session_version'])) {
            return false;
        }

        try {
            $stmt = Database::getInstance()->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $currentVersion = $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }

        return $currentVersion !== false && (int) $currentVersion === (int) $_SESSION['session_version'];
    }

    private static function sessionRecordIsActive(int $userId): bool
    {
        try {
            $sessionModel = new UserSession();
            $active = $sessionModel->currentIsActive($userId, (int) $_SESSION['session_version']);
            if ($active) {
                $sessionModel->touchCurrent($userId);
            }
            return $active;
        } catch (Throwable) {
            return true;
        }
    }
}
