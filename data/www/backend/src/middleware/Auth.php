<?php

require_once __DIR__ . '/../../config/app.php';
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
            'secure'   => false,    // Set to true when serving over HTTPS
            'httponly' => true,     // Blocks JS access to the session cookie
            'samesite' => 'Strict',
        ]);

        session_start();
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

        return (int) $_SESSION['user_id'];
    }
}