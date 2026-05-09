CREATE TABLE IF NOT EXISTS recovery_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    attempt_type  VARCHAR(16)  NOT NULL,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_recovery_attempts_lookup (username, ip_address, attempt_type, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
