<?php

require_once __DIR__ . '/../../src/Database.php';

class SecurityEvent
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function record(?int $userId, ?string $username, string $eventType, array $metadata = []): void
    {
        $metadataJson = empty($metadata)
            ? null
            : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare(
            'INSERT INTO security_events (user_id, username, event_type, ip_address, user_agent, metadata_json)
             VALUES (:user_id, :username, :event_type, :ip_address, :user_agent, :metadata_json)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':username' => $username,
            ':event_type' => $eventType,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            ':metadata_json' => $metadataJson === false ? null : $metadataJson,
        ]);
    }

    public function findLatestForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT id, username, event_type, ip_address, user_agent, metadata_json, created_at
             FROM security_events
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':user_id' => $userId]);

        return array_map(static function (array $row): array {
            $metadata = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode((string) $row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            unset($row['metadata_json']);
            $row['metadata'] = $metadata;
            return $row;
        }, $stmt->fetchAll());
    }
}
