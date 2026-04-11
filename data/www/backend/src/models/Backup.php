<?php

require_once __DIR__ . '/../../src/Database.php';

/**
 * Backup
 *
 * Data-access layer for the `backups` table.
 */
class Backup
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Log a new backup entry.
     */
    public function create(int $userId, string $filename): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO backups (user_id, filename) VALUES (:user_id, :filename)'
        );
        $stmt->execute([':user_id' => $userId, ':filename' => $filename]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Fetch all backup records for a user, newest first.
     */
    public function findAllByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, filename, created_at, restored_at
             FROM   backups
             WHERE  user_id = :user_id
             ORDER  BY created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a backup as restored by filename.
     */
    public function markRestored(int $userId, string $filename): void
    {
        $stmt = $this->db->prepare(
            'UPDATE backups
             SET    restored_at = NOW()
             WHERE  user_id = :user_id AND filename = :filename'
        );
        $stmt->execute([':user_id' => $userId, ':filename' => $filename]);
    }
}