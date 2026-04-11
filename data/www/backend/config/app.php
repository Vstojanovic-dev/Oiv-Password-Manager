<?php

// ---------------------------------------------------------------------------
// Application configuration
// ---------------------------------------------------------------------------

define('APP_NAME',    'Password Manager');
define('APP_VERSION', '1.0.0');
define('APP_ENV',     'development'); // Change to 'production' when deploying

// Session
define('SESSION_TIMEOUT_MINUTES', 15); // Default session timeout; can be overridden per user in the DB

// Password hashing
define('HASH_ALGO', PASSWORD_BCRYPT); // Options: PASSWORD_BCRYPT, PASSWORD_ARGON2ID

// API response
define('API_VERSION', 'v1');