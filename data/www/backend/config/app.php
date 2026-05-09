<?php

// ---------------------------------------------------------------------------
// Application configuration
// ---------------------------------------------------------------------------

function env_value(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

define('APP_NAME',    'Password Manager');
define('APP_VERSION', '1.0.0');
define('APP_ENV',     env_value('APP_ENV', 'development'));
define('APP_SECRET',  env_value('APP_SECRET', ''));

if (APP_SECRET === '') {
    throw new RuntimeException('APP_SECRET must be configured.');
}
if (APP_ENV === 'production' && strlen(APP_SECRET) < 32) {
    throw new RuntimeException('APP_SECRET must be at least 32 characters in production.');
}

// Session
define('SESSION_TIMEOUT_MINUTES', (int) env_value('SESSION_TIMEOUT_MINUTES', '15'));

// Password hashing
define('HASH_ALGO', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);

// HTTP
define('ALLOWED_ORIGINS', env_value('ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000'));

// Storage
define('BACKUP_DIR', env_value('BACKUP_DIR', __DIR__ . '/../storage/backups'));
define('BACKUP_MAX_BYTES', (int) env_value('BACKUP_MAX_BYTES', (string) (2 * 1024 * 1024)));
define('BACKUP_MAX_ACCOUNTS', (int) env_value('BACKUP_MAX_ACCOUNTS', '1000'));
define('JSON_REQUEST_MAX_BYTES', (int) env_value('JSON_REQUEST_MAX_BYTES', (string) (1024 * 1024)));

// API response
define('API_VERSION', 'v1');
