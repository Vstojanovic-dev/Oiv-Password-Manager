<?php

// ---------------------------------------------------------------------------
// CORS headers
// Handled here in PHP rather than .htaccess to avoid needing mod_headers.
// Adjust the allowed origin to match your React dev server port.
// ---------------------------------------------------------------------------
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/src/middleware/Auth.php';
require_once __DIR__ . '/routes/api.php';