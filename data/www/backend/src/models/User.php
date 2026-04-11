<?php

require_once __DIR__ . '/../../src/Database.php';

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find a user by their username.
     * Returns the full row as an associative array, or null if not found.
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, master_password_hash, session_timeout_minutes,
                    created_at, updated_at
             FROM   users
             WHERE  username = :username
             LIMIT  1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find a user by their primary key.
     * Returns the full row, or null if not found.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, master_password_hash, session_timeout_minutes,
                    created_at, updated_at
             FROM   users
             WHERE  id = :id
             LIMIT  1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Create a new user.
     * The password must already be hashed before calling this.
     * Returns the new user's ID.
     */
    public function create(string $username, string $passwordHash, ?int $timeoutMinutes = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, master_password_hash, session_timeout_minutes)
             VALUES (:username, :hash, :timeout)'
        );
        $stmt->execute([
            ':username' => $username,
            ':hash'     => $passwordHash,
            ':timeout'  => $timeoutMinutes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the session timeout for a user.
     */
    public function updateTimeout(int $userId, int $minutes): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET session_timeout_minutes = :timeout WHERE id = :id'
        );
        $stmt->execute([':timeout' => $minutes, ':id' => $userId]);
    }
}