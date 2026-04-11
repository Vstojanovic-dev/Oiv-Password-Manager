<?php

// ---------------------------------------------------------------------------
// Database configuration
// All values should match what is set in docker-compose.yml
// ---------------------------------------------------------------------------

define('DB_HOST',     'podatkovna-baza'); // MySQL container hostname (set via 'hostname:' in docker-compose.yml)
define('DB_PORT',     '3306');
define('DB_NAME',     'password_manager');
define('DB_USER',     'root');
define('DB_PASSWORD', 'superVarnoGeslo'); // Must match MYSQL_ROOT_PASSWORD in docker-compose.yml
define('DB_CHARSET',  'utf8mb4');