CREATE DATABASE IF NOT EXISTS password_manager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE password_manager;

CREATE TABLE IF NOT EXISTS users (
    id                       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username                 VARCHAR(64)     NOT NULL,
    master_password_hash     VARCHAR(255)    NOT NULL,
    kdf_salt                 VARCHAR(64)     NOT NULL,
    encrypted_vault_key      TEXT                NULL DEFAULT NULL,
    recovery_reset_token_hash VARCHAR(64)         NULL DEFAULT NULL,
    recovery_reset_expires_at DATETIME            NULL DEFAULT NULL,
    recovery_reset_code_id   INT UNSIGNED        NULL DEFAULT NULL,
    recovery_reset_encrypted_vault_key TEXT       NULL DEFAULT NULL,
    session_version          INT UNSIGNED    NOT NULL DEFAULT 1,
    session_timeout_minutes  INT UNSIGNED        NULL DEFAULT NULL,
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                      ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED    NOT NULL,
    site_name           VARCHAR(128)    NOT NULL,
    site_url            VARCHAR(512)        NULL DEFAULT NULL,
    username            VARCHAR(128)    NOT NULL,
    encrypted_password  TEXT            NOT NULL,
    favorite            TINYINT(1)      NOT NULL DEFAULT 0,
    category            VARCHAR(64)         NULL DEFAULT NULL,
    deleted_at          DATETIME            NULL DEFAULT NULL,
    last_used_at        DATETIME            NULL DEFAULT NULL,
    password_updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes               TEXT                NULL DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_accounts_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_accounts_user_id (user_id),
    INDEX idx_accounts_user_deleted (user_id, deleted_at),
    INDEX idx_accounts_site_name (site_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS backups (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NOT NULL,
    filename     VARCHAR(255)    NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at  DATETIME            NULL DEFAULT NULL,

    PRIMARY KEY (id),
    CONSTRAINT fk_backups_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_backups_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_login_attempts_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recovery_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    attempt_type  VARCHAR(16)  NOT NULL,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_recovery_attempts_lookup (username, ip_address, attempt_type, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS security_events (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED     NULL DEFAULT NULL,
    username       VARCHAR(64)      NULL DEFAULT NULL,
    event_type     VARCHAR(64)  NOT NULL,
    ip_address     VARCHAR(45)      NULL DEFAULT NULL,
    user_agent     VARCHAR(255)     NULL DEFAULT NULL,
    metadata_json  TEXT             NULL DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_security_events_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_security_events_user_created (user_id, created_at),
    INDEX idx_security_events_type_created (event_type, created_at)
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
