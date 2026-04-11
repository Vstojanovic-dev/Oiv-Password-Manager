<?php

require_once __DIR__ . '/../../src/models/User.php';
require_once __DIR__ . '/../../utils/Response.php';
require_once __DIR__ . '/../../config/app.php';

/**
 * AuthController
 *
 * Handles all authentication endpoints:
 *   POST /auth/login   – verify master password, start session
 *   POST /auth/logout  – destroy session
 *   GET  /auth/status  – return current session state
 */
class AuthController
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    // -------------------------------------------------------------------------
    // POST /auth/login
    // Body: { "username": "...", "password": "..." }
    // -------------------------------------------------------------------------
    public function login(): void
    {
        $body = $this->parseJsonBody();

        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            Response::error('Username and password are required.', 422);
        }

        $user = $this->userModel->findByUsername($username);

        // Use a consistent error message whether the user exists or not,
        // to avoid leaking which usernames are registered.
        if ($user === null || !password_verify($password, $user['master_password_hash'])) {
            Response::error('Invalid username or password.', 401);
        }

        // Regenerate session ID on login to prevent session fixation attacks
        session_regenerate_id(true);

        $_SESSION['user_id']             = $user['id'];
        $_SESSION['username']            = $user['username'];
        $_SESSION['master_password_hash'] = $user['master_password_hash'];
        $_SESSION['logged_in_at']        = time();
        $_SESSION['last_active']         = time();
        $_SESSION['timeout_minutes']     = $user['session_timeout_minutes']
                                           ?? SESSION_TIMEOUT_MINUTES;

        Response::success([
            'message'  => 'Login successful.',
            'username' => $user['username'],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /auth/logout
    // -------------------------------------------------------------------------
    public function logout(): void
    {
        // Clear session data, destroy the session, and expire the cookie
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

        Response::success(['message' => 'Logged out successfully.']);
    }

    // -------------------------------------------------------------------------
    // GET /auth/status
    // -------------------------------------------------------------------------
    public function status(): void
    {
        if (empty($_SESSION['user_id'])) {
            Response::success(['authenticated' => false]);
        }

        // Check timeout
        $timeoutSeconds = ($_SESSION['timeout_minutes'] ?? SESSION_TIMEOUT_MINUTES) * 60;
        $idle = time() - ($_SESSION['last_active'] ?? 0);

        if ($idle > $timeoutSeconds) {
            session_destroy();
            Response::success(['authenticated' => false, 'reason' => 'Session timed out.']);
        }

        Response::success([
            'authenticated' => true,
            'username'      => $_SESSION['username'],
            'idle_seconds'  => $idle,
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decode the JSON request body. Sends a 400 error if it's malformed.
     */
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