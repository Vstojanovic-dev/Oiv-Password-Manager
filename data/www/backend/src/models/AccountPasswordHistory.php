<?php

require_once __DIR__ . '/../../src/Database.php';

class AccountPasswordHistory
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(int $accountId, int $userId, string $encryptedPassword, ?string $passwordUpdatedAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO account_password_history (account_id, user_id, encrypted_password, password_updated_at)
             VALUES (:account_id, :user_id, :encrypted_password, :password_updated_at)'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':user_id' => $userId,
            ':encrypted_password' => $encryptedPassword,
            ':password_updated_at' => $passwordUpdatedAt,
        ]);
    }

    public function findByAccount(int $accountId, int $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, account_id, user_id, encrypted_password, password_updated_at, created_at
             FROM account_password_history
             WHERE account_id = :account_id AND user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
