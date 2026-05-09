<?php

require_once __DIR__ . '/app.php';

// ---------------------------------------------------------------------------
// Database configuration
// Values are read from environment variables.
// ---------------------------------------------------------------------------

define('DB_HOST',     env_value('DB_HOST', 'podatkovna-baza'));
define('DB_PORT',     env_value('DB_PORT', '3306'));
define('DB_NAME',     env_value('DB_NAME', 'password_manager'));
define('DB_USER',     env_value('DB_USER', ''));
define('DB_PASSWORD', env_value('DB_PASSWORD', ''));
define('DB_CHARSET',  env_value('DB_CHARSET', 'utf8mb4'));

if (DB_USER === '' || DB_PASSWORD === '') {
    throw new RuntimeException('Database credentials must be configured.');
}
