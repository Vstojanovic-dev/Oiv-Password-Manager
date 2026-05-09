USE password_manager;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'encrypted_vault_key') = 0,
    'ALTER TABLE users ADD COLUMN encrypted_vault_key TEXT NULL AFTER kdf_salt',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_reset_token_hash') = 0,
    'ALTER TABLE users ADD COLUMN recovery_reset_token_hash VARCHAR(64) NULL AFTER encrypted_vault_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_reset_expires_at') = 0,
    'ALTER TABLE users ADD COLUMN recovery_reset_expires_at DATETIME NULL AFTER recovery_reset_token_hash',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_reset_code_id') = 0,
    'ALTER TABLE users ADD COLUMN recovery_reset_code_id INT UNSIGNED NULL AFTER recovery_reset_expires_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_reset_encrypted_vault_key') = 0,
    'ALTER TABLE users ADD COLUMN recovery_reset_encrypted_vault_key TEXT NULL AFTER recovery_reset_code_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS recovery_codes (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id              INT UNSIGNED NOT NULL,
    code_hash            CHAR(64)     NOT NULL,
    recovery_salt        VARCHAR(64)  NOT NULL,
    encrypted_vault_key  TEXT         NOT NULL,
    used_at              DATETIME         NULL DEFAULT NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_recovery_codes_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    UNIQUE KEY uq_recovery_codes_hash (code_hash),
    INDEX idx_recovery_codes_user_unused (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
