USE password_manager;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'deleted_at') = 0,
    'ALTER TABLE accounts ADD COLUMN deleted_at DATETIME NULL AFTER category',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND INDEX_NAME = 'idx_accounts_user_deleted') = 0,
    'CREATE INDEX idx_accounts_user_deleted ON accounts (user_id, deleted_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS account_password_history (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id         INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    encrypted_password TEXT         NOT NULL,
    password_updated_at DATETIME        NULL DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_account_password_history_account
        FOREIGN KEY (account_id) REFERENCES accounts (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_account_password_history_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_account_password_history_account (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    session_id_hash CHAR(64)     NOT NULL,
    session_version INT UNSIGNED NOT NULL,
    ip_address      VARCHAR(45)      NULL DEFAULT NULL,
    user_agent      VARCHAR(255)     NULL DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      DATETIME         NULL DEFAULT NULL,

    PRIMARY KEY (id),
    CONSTRAINT fk_user_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    UNIQUE KEY uq_user_sessions_hash (session_id_hash),
    INDEX idx_user_sessions_user_active (user_id, revoked_at, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
