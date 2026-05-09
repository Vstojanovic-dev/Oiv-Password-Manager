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
    public function findAllByUser(
        int $userId,
        ?string $search = null,
        ?bool $favorite = null,
        ?string $category = null,
        bool $deletedOnly = false
    ): array
    {
        $where = ['user_id = :user_id', $deletedOnly ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $params = [':user_id' => $userId];

        if ($search !== null && trim($search) !== '') {
            $where[] = '(site_name LIKE :search_site OR site_url LIKE :search_url OR username LIKE :search_username)';
            $like = '%' . trim($search) . '%';
            $params[':search_site'] = $like;
            $params[':search_url'] = $like;
            $params[':search_username'] = $like;
        }
        if ($favorite !== null) {
            $where[] = 'favorite = :favorite';
            $params[':favorite'] = $favorite ? 1 : 0;
        }
        if ($category !== null && trim($category) !== '') {
            $where[] = 'category = :category';
            $params[':category'] = trim($category);
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, site_name, site_url, username,
                    encrypted_password, favorite, category, deleted_at, last_used_at, password_updated_at,
                    notes, created_at, updated_at
             FROM   accounts
             WHERE  ' . implode(' AND ', $where) . '
             ORDER  BY site_name ASC'
        );
        $stmt->execute($params);

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
                    encrypted_password, favorite, category, deleted_at, last_used_at, password_updated_at,
                    notes, created_at, updated_at
             FROM   accounts
             WHERE  id = :id AND user_id = :user_id AND deleted_at IS NULL
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
        bool $favorite,
        ?string $category,
        ?string $notes,
        ?string $lastUsedAt = null,
        ?string $passwordUpdatedAt = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO accounts
                (user_id, site_name, site_url, username, encrypted_password, favorite, category, last_used_at, password_updated_at, notes)
             VALUES
                (:user_id, :site_name, :site_url, :username, :encrypted_password, :favorite, :category, :last_used_at, COALESCE(:password_updated_at, CURRENT_TIMESTAMP), :notes)'
        );
        $stmt->execute([
            ':user_id'            => $userId,
            ':site_name'          => $siteName,
            ':site_url'           => $siteUrl,
            ':username'           => $username,
            ':encrypted_password' => $encryptedPassword,
            ':favorite'           => $favorite ? 1 : 0,
            ':category'           => $category,
            ':last_used_at'       => $lastUsedAt,
            ':password_updated_at' => $passwordUpdatedAt,
            ':notes'              => $notes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findAnyByIdAndUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, site_name, site_url, username,
                    encrypted_password, favorite, category, deleted_at, last_used_at, password_updated_at,
                    notes, created_at, updated_at
             FROM   accounts
             WHERE  id = :id AND user_id = :user_id
             LIMIT  1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
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
        bool $favorite,
        ?string $category,
        ?string $notes
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET    site_name          = :site_name,
                    site_url           = :site_url,
                    username           = :username,
                    encrypted_password = :encrypted_password,
                    favorite           = :favorite,
                    category           = :category,
                    password_updated_at = NOW(),
                    notes              = :notes
             WHERE  id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':site_name'          => $siteName,
            ':site_url'           => $siteUrl,
            ':username'           => $username,
            ':encrypted_password' => $encryptedPassword,
            ':favorite'           => $favorite ? 1 : 0,
            ':category'           => $category,
            ':notes'              => $notes,
            ':id'                 => $id,
            ':user_id'            => $userId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-delete an account. Scoped to user_id for safety.
     * Returns true if a row was actually deleted.
     */
    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE accounts SET deleted_at = NOW() WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE accounts SET deleted_at = NULL WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function permanentlyDelete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM accounts WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update only the encrypted_password column (used when re-wrapping vault data).
     */
    public function updateEncryptedPassword(int $id, int $userId, string $newCipher): void
    {
        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET    encrypted_password = :cipher,
                    password_updated_at = NOW()
             WHERE  id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':cipher'  => $newCipher,
            ':id'      => $id,
            ':user_id' => $userId,
        ]);
    }

    public function markUsed(int $id, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE accounts
             SET last_used_at = NOW()
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }
}
