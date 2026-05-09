<?php

require_once __DIR__ . '/../../src/Database.php';

class LoginAttempt
{
    private const WINDOW_MINUTES = 15;
    private const MAX_FAILURES = 5;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function tooMany(string $username, string $ipAddress): bool
    {
        $this->deleteExpired();
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS failures
             FROM login_attempts
             WHERE username = :username
               AND ip_address = :ip
               AND attempted_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([':username' => $username, ':ip' => $ipAddress]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES;
    }

    public function recordFailure(string $username, string $ipAddress): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip)'
        );
        $stmt->execute([':username' => $username, ':ip' => $ipAddress]);
    }

    public function clear(string $username, string $ipAddress): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE username = :username AND ip_address = :ip'
        );
        $stmt->execute([':username' => $username, ':ip' => $ipAddress]);
    }

    private function deleteExpired(): void
    {
        $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');
    }
}
