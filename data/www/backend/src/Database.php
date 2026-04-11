<?php

require_once __DIR__ . '/../config/database.php';

class Database
{
    private static ?PDO $instance = null;

    // Prevent direct instantiation and cloning
    private function __construct() {}
    private function __clone() {}

    /**
     * Returns the shared PDO instance, creating it if it doesn't exist yet.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,    // Throw exceptions on errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,          // Return rows as associative arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                     // Use real prepared statements
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            } catch (PDOException $e) {
                // Don't expose raw PDO messages — send a clean JSON error
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Database connection failed.',
                    // Uncomment the line below during local development only:
                    // 'debug' => $e->getMessage(),
                ]);
                exit;
            }
        }

        return self::$instance;
    }
}