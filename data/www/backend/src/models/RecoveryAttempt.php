<?php

require_once __DIR__ . '/../../src/Database.php';

class RecoveryAttempt
{
    private const WINDOW_MINUTES = 15;
    private const MAX_FAILURES = 5;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function tooMany(string $username, string $ipAddress, string $attemptType): bool
    {
        $this->deleteExpired();
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS failures
             FROM recovery_attempts
             WHERE username = :username
               AND ip_address = :ip
               AND attempt_type = :attempt_type
               AND attempted_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([
            ':username' => $username,
            ':ip' => $ipAddress,
            ':attempt_type' => $attemptType,
        ]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES;
    }

    public function recordFailure(string $username, string $ipAddress, string $attemptType): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO recovery_attempts (username, ip_address, attempt_type)
             VALUES (:username, :ip, :attempt_type)'
        );
        $stmt->execute([
            ':username' => $username,
            ':ip' => $ipAddress,
            ':attempt_type' => $attemptType,
        ]);
    }

    public function clear(string $username, string $ipAddress, string $attemptType): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM recovery_attempts
             WHERE username = :username AND ip_address = :ip AND attempt_type = :attempt_type'
        );
        $stmt->execute([
            ':username' => $username,
            ':ip' => $ipAddress,
            ':attempt_type' => $attemptType,
        ]);
    }

    private function deleteExpired(): void
    {
        $this->db->exec('DELETE FROM recovery_attempts WHERE attempted_at < (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');
    }
}
