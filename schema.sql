-- =============================================================================
-- Password Manager – Database Schema
-- =============================================================================
-- Run this script once to initialise the database.
-- In phpMyAdmin: select the 'password_manager' database, open the SQL tab,
-- paste this entire file and click Go.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS password_manager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE password_manager;

-- -----------------------------------------------------------------------------
-- Table: users
--
-- Stores master accounts. Designed for a single user per installation, but
-- structured as a proper table so multi-user support can be added later.
--
-- master_password_hash  : bcrypt/argon2 hash of the master password.
--                         Never store the plaintext password.
-- session_timeout_minutes : per-user override for the auto-logout timer.
--                           NULL means use the global app default (15 min).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username                 VARCHAR(64)     NOT NULL,
    master_password_hash     VARCHAR(255)    NOT NULL,
    session_timeout_minutes  INT UNSIGNED        NULL DEFAULT NULL,
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                      ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Table: accounts
--
-- One row per saved credential entry.
--
-- encrypted_password : the stored password is AES-encrypted (NOT hashed),
--                      because it must be retrieved and shown to the user.
--                      Encryption/decryption is handled in PHP (Phase 4).
-- notes              : optional free-text field for the user.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED    NOT NULL,
    site_name           VARCHAR(128)    NOT NULL,
    site_url            VARCHAR(512)        NULL DEFAULT NULL,
    username            VARCHAR(128)    NOT NULL,
    encrypted_password  TEXT            NOT NULL,
    notes               TEXT                NULL DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_accounts_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    -- Speeds up "fetch all accounts for user X" and search queries
    INDEX idx_accounts_user_id  (user_id),
    INDEX idx_accounts_site_name (site_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Table: backups
--
-- Metadata log for every backup file generated (Phase 5).
-- The actual backup content is written to disk as a JSON file;
-- this table just tracks when it was made and whether a restore happened.
-- -----------------------------------------------------------------------------
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


-- =============================================================================
-- Seed data – development only
-- Creates one test user so backend routes can be tested via Postman
-- immediately without needing the auth flow (Phase 3) to be finished first.
--
-- Credentials:
--   username : testuser
--   password : test1234   (bcrypt hash below)
--
-- !! Remove or comment out this block before any kind of deployment !!
-- =============================================================================
INSERT IGNORE INTO users (username, master_password_hash, session_timeout_minutes)
VALUES (
    'testuser',
    '$2y$12$YM9kWrOTVEDiSPRCpSRS0.z7cMkTFzEEEGHqC8lo1ydThBNPtkJ5.',  -- bcrypt of 'test1234'
    15
);