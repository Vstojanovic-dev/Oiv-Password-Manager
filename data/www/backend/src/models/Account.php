<?php

require_once __DIR__ . '/../../src/Database.php';

/**
 * Account
 *
 * Data-access layer for the `accounts` table.
 * All queries are scoped to a specific user_id — a user can never
 * read or modify another user's accounts.
 *
 * NOTE: encrypted_password is stored and retrieved as-is here.
 *       Encryption and decryption are handled one layer up in the
 *       AccountController (Phase 4).
 */
class Account
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Fetch all accounts belonging to a user.
     * Optional $search filters by site_name, site_url, or username.
     *
     * @return array<int, array>
     */
    public function findAllByUser(int $userId, ?string $search = null): array
    {
        if ($search !== null && $search !== '') {
            $like = '%' . $search . '%';
            $stmt = $this->db->prepare(
                'SELECT id, user_id, site_name, site_url, username,
                        encrypted_password, notes, created_at, updated_at
                 FROM   accounts
                 WHERE  user_id = :user_id
                   AND  (site_name LIKE :s1 OR site_url LIKE :s2 OR username LIKE :s3)
                 ORDER  BY site_name ASC'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':s1'      => $like,
                ':s2'      => $like,
                ':s3'      => $like,
            ]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, user_id, site_name, site_url, username,
                        encrypted_password, notes, created_at, updated_at
                 FROM   accounts
                 WHERE  user_id = :user_id
                 ORDER  BY site_name ASC'
            );
            $stmt->execute([':user_id' => $userId]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Fetch a single account by ID, scoped to the given user.
     * Returns null if not found or if it belongs to a different user.
     */
    public function findByIdAndUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, site_name, site_url, username,
                    encrypted_password, notes, created_at, updated_at
             FROM   accounts
             WHERE  id = :id AND user_id = :user_id
             LIMIT  1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Insert a new account. Returns the new row's ID.
     */
    public function create(
        int $userId,
        string $siteName,
        ?string $siteUrl,
        string $username,
        string $encryptedPassword,
        ?string $notes
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO accounts
                (user_id, site_name, site_url, username, encrypted_password, notes)
             VALUES
                (:user_id, :site_name, :site_url, :username, :encrypted_password, :notes)'
        );
        $stmt->execute([
            ':user_id'            => $userId,
            ':site_name'          => $siteName,
            ':site_url'           => $siteUrl,
            ':username'           => $username,
            ':encrypted_password' => $encryptedPassword,
            ':notes'              => $notes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing account. Scoped to user_id for safety.
     * Returns true if a row was actually updated.
     */
    public function update(
        int $id,
        int $userId,
        string $siteName,
        ?string $siteUrl,
        string $username,
        string $encryptedPassword,
        ?string $notes
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET    site_name          = :site_name,
                    site_url           = :site_url,
                    username           = :username,
                    encrypted_password = :encrypted_password,
                    notes              = :notes
             WHERE  id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':site_name'          => $siteName,
            ':site_url'           => $siteUrl,
            ':username'           => $username,
            ':encrypted_password' => $encryptedPassword,
            ':notes'              => $notes,
            ':id'                 => $id,
            ':user_id'            => $userId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an account. Scoped to user_id for safety.
     * Returns true if a row was actually deleted.
     */
    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM accounts WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }
}