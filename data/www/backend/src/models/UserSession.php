<?php

require_once __DIR__ . '/../../src/Database.php';

class UserSession
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function hashSessionId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function upsertCurrent(int $userId, int $sessionVersion): void
    {
        $hash = self::hashSessionId(session_id());
        $stmt = $this->db->prepare(
            'INSERT INTO user_sessions (user_id, session_id_hash, session_version, ip_address, user_agent)
             VALUES (:user_id, :session_id_hash, :session_version, :ip_address, :user_agent)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                session_version = VALUES(session_version),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                last_seen_at = NOW(),
                revoked_at = NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => $hash,
            ':session_version' => $sessionVersion,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    }

    public function currentIsActive(int $userId, int $sessionVersion): bool
    {
        $stmt = $this->db->prepare(
            'SELECT revoked_at, session_version
             FROM user_sessions
             WHERE user_id = :user_id AND session_id_hash = :session_id_hash
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => self::hashSessionId(session_id()),
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return true;
        }

        return $row['revoked_at'] === null && (int) $row['session_version'] === $sessionVersion;
    }

    public function touchCurrent(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_sessions SET last_seen_at = NOW()
             WHERE user_id = :user_id AND session_id_hash = :session_id_hash'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id_hash' => self::hashSessionId(session_id()),
        ]);
    }

    public function revokeCurrent(int $userId): void
    {
        $this->revokeByHash($userId, self::hashSessionId(session_id()));
    }

    public function revokeById(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, NOW())
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function revokeAllForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, NOW()) WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
    }

    public function findAllForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, session_version, ip_address, user_agent, created_at, last_seen_at, revoked_at, session_id_hash
             FROM user_sessions
             WHERE user_id = :user_id
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        $currentHash = self::hashSessionId(session_id());
        return array_map(static function (array $row) use ($currentHash): array {
            $row['current'] = hash_equals($row['session_id_hash'], $currentHash);
            unset($row['session_id_hash']);
            return $row;
        }, $stmt->fetchAll());
    }

    private function revokeByHash(int $userId, string $hash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, NOW())
             WHERE user_id = :user_id AND session_id_hash = :session_id_hash'
        );
        $stmt->execute([':user_id' => $userId, ':session_id_hash' => $hash]);
    }
}
