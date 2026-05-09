<?php

require_once __DIR__ . '/../../src/Database.php';

class RecoveryCode
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function deleteUnusedForUser(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = :user_id AND used_at IS NULL');
        $stmt->execute([':user_id' => $userId]);
    }

    public function create(int $userId, string $codeHash, string $salt, string $encryptedVaultKey): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO recovery_codes (user_id, code_hash, recovery_salt, encrypted_vault_key)
             VALUES (:user_id, :code_hash, :recovery_salt, :encrypted_vault_key)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':code_hash' => $codeHash,
            ':recovery_salt' => $salt,
            ':encrypted_vault_key' => $encryptedVaultKey,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findUnusedByUserAndHash(int $userId, string $codeHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, code_hash, recovery_salt, encrypted_vault_key, used_at, created_at
             FROM recovery_codes
             WHERE user_id = :user_id AND code_hash = :code_hash AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId, ':code_hash' => $codeHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markUsed(int $id, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE recovery_codes SET used_at = NOW() WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }

    public function countUnused(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM recovery_codes WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
