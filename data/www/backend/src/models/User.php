<?php

require_once __DIR__ . '/../../src/Database.php';

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, master_password_hash, kdf_salt, encrypted_vault_key,
                    recovery_reset_token_hash, recovery_reset_expires_at,
                    recovery_reset_code_id, recovery_reset_encrypted_vault_key,
                    session_version, session_timeout_minutes, created_at, updated_at
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, master_password_hash, kdf_salt, encrypted_vault_key,
                    recovery_reset_token_hash, recovery_reset_expires_at,
                    recovery_reset_code_id, recovery_reset_encrypted_vault_key,
                    session_version, session_timeout_minutes, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(
        string $username,
        string $passwordHash,
        string $kdfSalt,
        string $encryptedVaultKey,
        ?int $timeoutMinutes = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, master_password_hash, kdf_salt, encrypted_vault_key, session_timeout_minutes)
             VALUES (:username, :hash, :kdf_salt, :encrypted_vault_key, :timeout)'
        );
        $stmt->execute([
            ':username' => $username,
            ':hash' => $passwordHash,
            ':kdf_salt' => $kdfSalt,
            ':encrypted_vault_key' => $encryptedVaultKey,
            ':timeout' => $timeoutMinutes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateTimeout(int $userId, int $minutes): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET session_timeout_minutes = :timeout WHERE id = :id'
        );
        $stmt->execute([':timeout' => $minutes, ':id' => $userId]);
    }

    public function updateMasterPasswordWrap(int $userId, string $newHash, string $newSalt, string $encryptedVaultKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET master_password_hash = :hash,
                 kdf_salt = :kdf_salt,
                 encrypted_vault_key = :encrypted_vault_key,
                 recovery_reset_token_hash = NULL,
                 recovery_reset_expires_at = NULL,
                 recovery_reset_code_id = NULL,
                 recovery_reset_encrypted_vault_key = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => $newHash,
            ':kdf_salt' => $newSalt,
            ':encrypted_vault_key' => $encryptedVaultKey,
            ':id' => $userId,
        ]);
    }

    public function updateMasterPasswordWrapAndInvalidateSessions(int $userId, string $newHash, string $newSalt, string $encryptedVaultKey): int
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET master_password_hash = :hash,
                 kdf_salt = :kdf_salt,
                 encrypted_vault_key = :encrypted_vault_key,
                 recovery_reset_token_hash = NULL,
                 recovery_reset_expires_at = NULL,
                 recovery_reset_code_id = NULL,
                 recovery_reset_encrypted_vault_key = NULL,
                 session_version = session_version + 1
             WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => $newHash,
            ':kdf_salt' => $newSalt,
            ':encrypted_vault_key' => $encryptedVaultKey,
            ':id' => $userId,
        ]);

        $stmt = $this->db->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function updateVaultWrap(int $userId, string $encryptedVaultKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET encrypted_vault_key = :encrypted_vault_key WHERE id = :id'
        );
        $stmt->execute([':encrypted_vault_key' => $encryptedVaultKey, ':id' => $userId]);
    }

    public function setRecoveryResetToken(int $userId, string $tokenHash, string $expiresAt, int $recoveryCodeId, string $encryptedVaultKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET recovery_reset_token_hash = :token_hash,
                 recovery_reset_expires_at = :expires_at,
                 recovery_reset_code_id = :recovery_code_id,
                 recovery_reset_encrypted_vault_key = :encrypted_vault_key
             WHERE id = :id'
        );
        $stmt->execute([
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
            ':recovery_code_id' => $recoveryCodeId,
            ':encrypted_vault_key' => $encryptedVaultKey,
            ':id' => $userId,
        ]);
    }

    public function findByRecoveryResetToken(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, master_password_hash, kdf_salt, encrypted_vault_key,
                    recovery_reset_token_hash, recovery_reset_expires_at,
                    recovery_reset_code_id, recovery_reset_encrypted_vault_key,
                    session_version, session_timeout_minutes, created_at, updated_at
             FROM users
             WHERE recovery_reset_token_hash = :token_hash
               AND recovery_reset_expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function incrementSessionVersion(int $userId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET session_version = session_version + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);

        $stmt = $this->db->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
