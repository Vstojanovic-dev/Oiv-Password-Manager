USE password_manager;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS kdf_salt VARCHAR(64) NULL AFTER master_password_hash;

UPDATE users
SET kdf_salt = TO_BASE64(RANDOM_BYTES(16))
WHERE kdf_salt IS NULL OR kdf_salt = '';

ALTER TABLE users
    MODIFY kdf_salt VARCHAR(64) NOT NULL;

CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_login_attempts_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

