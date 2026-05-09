USE password_manager;

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
