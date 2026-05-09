<?php

// ---------------------------------------------------------------------------
// CORS headers
// Handled here in PHP rather than .htaccess to avoid needing mod_headers.
// Adjust the allowed origin to match your React dev server port.
// ---------------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/app.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none';");
header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()");
header('Cross-Origin-Resource-Policy: same-site');
header('Cache-Control: no-store');
if (APP_ENV === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_map('trim', explode(',', ALLOWED_ORIGINS));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/utils/Response.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    if (!headers_sent()) {
        Response::error('Internal server error.', 500);
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
    exit;
});

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)
    && !str_starts_with($contentType, 'multipart/form-data')
    && $contentLength > JSON_REQUEST_MAX_BYTES) {
    Response::error('Request body is too large.', 413);
}

require_once __DIR__ . '/src/middleware/Auth.php';
require_once __DIR__ . '/routes/api.php';
