# Password Manager

## Current backend security model

- Mutating API requests (`POST`, `PUT`, `DELETE`) require a CSRF token from `GET /backend/api/auth/csrf`, sent as `X-CSRF-Token`.
- On `main`, vault passwords are encrypted as `v2:<base64(nonce || ciphertext)>` with `sodium_crypto_secretbox`; the vault key is derived from the master password and per-user `kdf_salt`.
- On `recovery-revision`, every user has a random 32-byte `vault_key`. The master password only unlocks that key, and recovery codes each store a separate encrypted copy of that same key.
- `APP_SECRET` is a server-side pepper used for password hashes, master-key derivation inputs, and recovery-code hashing. It must stay stable for an existing database. If it is changed or lost, existing login and recovery flows can become invalid.
- In `APP_ENV=production`, `APP_SECRET` must be a non-default value with at least 32 characters.
- Production deployments must run behind HTTPS, for example through a reverse proxy. The local Docker stack stays HTTP-only for development; secure session cookies are enabled automatically when `APP_ENV=production`.
- Encrypted backups require `backup_password`; backup files no longer store plaintext vault data and are stored outside the web root through `BACKUP_DIR`.
- Existing baseline databases must run `migrations/001_security_hardening.sql`, `migrations/002_recovery_revision.sql`, `migrations/003_security_events.sql`, `migrations/004_session_management.sql`, `migrations/005_account_metadata.sql`, `migrations/006_security_edge_cases.sql`, then `migrations/007_product_lite_features.sql` on this branch. These migrations are idempotent for MySQL 8.4.

A college-level password manager built with React, PHP, and MySQL — containerised with Docker. This document covers everything you need to get the project running locally and understand how the backend is structured.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Getting Started](#getting-started)
3. [Initialising the Database](#initialising-the-database)
4. [Project Structure](#project-structure)
5. [Backend API](#backend-api)
6. [Testing with Postman](#testing-with-postman)
7. [Browser Extension](#browser-extension)
8. [Configuration Reference](#configuration-reference)

---

## Prerequisites

Make sure you have the following installed before you begin:

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Docker Compose)
- [Git](https://git-scm.com/)
- [Postman](https://www.postman.com/) — for backend API testing

---

## Getting Started

### 1. Clone the repository

```bash
git clone <repository-url>
cd PROJEKAT
```

### 2. Configure local secrets

```bash
cp .env.example .env
```

Edit `.env` and replace the placeholder values before starting the stack.

### 3. Start the containers

```bash
docker compose up --build
```

This starts three containers:

| Container | Purpose | Local URL |
|---|---|---|
| `spletni-streznik` | PHP + Apache web server | http://localhost:8000 |
| `podatkovna-baza` | MySQL database | internal only |
| `phpmyadmin` | Database GUI | http://localhost:8001 |

The `--build` flag is only needed the first time, or after modifying `docker-compose.yml`. On subsequent runs use `docker compose up`.

### 4. Stop the containers

```bash
docker compose down
```

To also wipe all stored database data:

```bash
docker compose down -v
```

---

## Initialising the Database

For a fresh Docker database, MySQL runs `schema.sql` automatically from `docker-compose.yml`.

1. Go to **http://localhost:8001** (phpMyAdmin)
2. Log in with username `root` and the `MYSQL_ROOT_PASSWORD` value from your local `.env`
3. Click the **SQL** tab at the top
4. For an existing database only, paste the required migration SQL files and click **Go**

There is **no seed user**. Create the first account through the React UI.

### First user (registration)

1. From the repo root, install and start the dev frontend:
   ```bash
   cd data/www/frontend
   npm install
   npm run dev
   ```
2. Open **http://localhost:3000**, click **Create account**, pick a username (3–64 characters) and a master password (at least 8 characters).

Alternatively, call `POST http://localhost:8000/backend/api/auth/register` with JSON `{"username":"...","password":"..."}` (see [Auth](#auth)).

> **Important on `recovery-revision`:** Generate recovery codes after login and store them outside the app. A valid unused recovery code can reset the master password while preserving the vault. Without a recovery code, forgotten master passwords still cannot be recovered.

### Verifying the setup

Send a request to `GET http://localhost:8000/backend/api/health`. A working setup returns:

```json
{
  "success": true,
  "data": {
    "api": "ok",
    "database": "ok"
  }
}
```

---

## Project Structure

```
PROJEKAT/
├── docker-compose.yml              # Defines and connects all three containers
├── schema.sql                      # Run once to initialise the database
│
└── data/
    ├── mysql/                      # MySQL data files (auto-generated, do not edit)
    └── www/
        ├── frontend/               # React application (separate responsibility)
        └── backend/                # PHP REST API
            ├── index.php           # Entry point — bootstraps the app and sets CORS headers
            ├── .htaccess           # Apache rewrite rules — routes all requests through index.php
            │
            ├── config/
            │   ├── app.php         # App-wide constants: env, session timeout, hash algorithm
            │   └── database.php    # DB credentials and connection parameters
            │
            ├── src/
            │   ├── Database.php    # PDO singleton — call Database::getInstance() anywhere
            │   ├── middleware/
            │   │   └── Auth.php    # Session startup + requireAuth() guard for protected routes
            │   ├── controllers/
            │   │   ├── AuthController.php    # register, login, logout, change password, status
            │   │   ├── AccountController.php # full CRUD for vault entries
            │   │   └── BackupController.php  # export, restore, history
            │   └── models/
            │       ├── User.php    # Data access for the users table
            │       ├── Account.php # Data access for the accounts table
            │       └── Backup.php  # Data access for the backups table
            │
            ├── routes/
            │   └── api.php         # Maps URL paths to controller methods
            │
            ├── backups/            # Legacy path; direct web access is denied by .htaccess
            │
            └── utils/
                └── Response.php    # Sends all JSON responses in a consistent envelope
```

---

## Backend API

### Response format

Every endpoint returns JSON in one of these two shapes:

```json
{ "success": true,  "data": { ... } }
{ "success": false, "error": "Human-readable error message" }
```

### Base URL

All requests go to: `http://localhost:8000/backend/api/`

---

### Health

#### `GET /health`
Checks that Apache, PHP, and MySQL are all reachable. No authentication required.

**Response**
```json
{
  "success": true,
  "data": {
    "api": "ok",
    "database": "ok"
  }
}
```

---

### Auth

#### `GET /auth/csrf`
Creates or returns the current session CSRF token. Send this token as `X-CSRF-Token` on every `POST`, `PUT`, and `DELETE` request.

---

#### `POST /auth/register`
Creates a new master account and starts a session (same cookie behaviour as login). No authentication required.

**Request body**
```json
{
  "username": "alice",
  "password": "your-master-password"
}
```

- `username`: 3–64 characters (trimmed).
- `password`: at least 8 characters.

**Response** — HTTP `201 Created`
```json
{
  "success": true,
  "data": {
    "message": "Registered.",
    "username": "alice"
  }
}
```

Returns `409` if the username is already taken.

---

#### `POST /auth/login`
Verifies the master password and starts a session. No authentication required.

**Request body**
```json
{
  "username": "alice",
  "password": "your-password"
}
```

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Login successful.",
    "username": "alice"
  }
}
```

---

#### `POST /auth/password`
Changes the master password while keeping all vault entries readable. Requires an active session.

On `recovery-revision`, this re-wraps the same random `vault_key` with the new master password. It does not re-encrypt every account row.

**Request body**
```json
{
  "current_password": "old-master-password",
  "new_password": "new-master-password"
}
```

- `new_password`: at least 8 characters and must differ from `current_password`.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Password updated."
  }
}
```

---

#### `POST /auth/recovery-codes`
Generates 8 one-time recovery codes. Requires an active session and CSRF token.

**Request body**
```json
{
  "current_password": "current-master-password"
}
```

The plaintext codes are returned once. Old unused codes are revoked when a new set is generated.

---

#### `POST /auth/forgot/verify`
Verifies a recovery code and returns a short-lived reset token. No active login required.

**Request body**
```json
{
  "username": "alice",
  "recovery_code": "AAAA-BBBB-CCCC-DDDD"
}
```

### Recovery flow smoke test

The recovery branch includes an end-to-end PowerShell test that starts the app, registers a user, logs in, creates one vault entry, generates recovery codes, resets the master password, confirms the old password fails, confirms the new password works, and verifies the stored vault password is unchanged.

The same flow is also covered by PHPUnit in `tests/RecoveryFlowTest.php`. The Docker Compose `phpunit` service waits for the backend healthcheck, installs Composer dependencies, and runs the test.

```powershell
docker compose run --rm phpunit
```

For a build-and-test run that returns the PHPUnit exit code:

```powershell
docker compose up -d --build mysql spletni-streznik
docker compose run --rm phpunit
```

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\tests\recovery-flow.ps1
```

If Docker is already running and you only want to run the API flow:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\tests\recovery-flow.ps1 -SkipDockerUp
```

---

#### `POST /auth/forgot/reset`
Uses the reset token to set a new master password, marks the recovery code as used, opens a new session, and returns a CSRF token.

**Request body**
```json
{
  "reset_token": "token-from-verify",
  "new_password": "new-master-password"
}
```

---

#### `POST /auth/logout`
Destroys the current session. Requires an active session.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Logged out successfully."
  }
}
```

---

#### `POST /auth/logout-all`
Invalidates all active sessions for the current user by incrementing the user's session version. Requires an active session and CSRF token.

The current session is destroyed as part of the response; other browser sessions become invalid on their next API request.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "All sessions logged out successfully."
  }
}
```

---

#### `GET /auth/status`
Returns the current session state including idle time. Requires an active session.

**Response**
```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "username": "testuser",
    "idle_seconds": 42,
    "timeout_seconds": 900
  }
}
```

If the session has expired or does not exist:
```json
{
  "success": true,
  "data": {
    "authenticated": false
  }
}
```

---

#### `GET /auth/security-events`
Returns the latest security/audit events for the logged-in user. Requires an active session.

Events include login success/failure, rate limiting, password changes, recovery-code actions, logout/logout-all, and encrypted backup/restore activity. Secret values such as master passwords, recovery codes, reset tokens, backup passwords, and vault passwords are never returned.

**Response**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "username": "alice",
      "event_type": "login_success",
      "ip_address": "172.18.0.1",
      "user_agent": null,
      "created_at": "2026-05-08 12:00:00",
      "metadata": {}
    }
  ]
}
```

---

### Accounts

All account endpoints require an active session. Vault passwords are encrypted at rest with the current `v2:` sodium secretbox format. List/detail responses are metadata-only; plaintext passwords are returned only by the dedicated password endpoint.

#### `GET /accounts`
Returns all accounts for the logged-in user, ordered alphabetically by site name.

**Optional query parameter:** `?search=github` — filters across `site_name`, `site_url`, and `username`.

**Response**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "site_name": "GitHub",
      "site_url": "https://github.com",
      "username": "me@example.com",
      "favorite": true,
      "category": "Work",
      "last_used_at": null,
      "password_updated_at": "2026-01-01 12:00:00",
      "notes": null,
      "created_at": "2026-01-01 12:00:00",
      "updated_at": "2026-01-01 12:00:00"
    }
  ]
}
```

---

#### `GET /accounts/{id}`
Returns a single account entry without plaintext password data.

**Response** — same shape as a single item from the list above. Returns `404` if the account does not exist or belongs to a different user.

---

#### `GET /accounts/{id}/password`
Returns the decrypted password only when the logged-in user owns the account.

**Response**
```json
{
  "success": true,
  "data": {
    "password": "hunter2"
  }
}
```

---

#### `POST /accounts`
Creates a new account entry.

**Request body**
```json
{
  "site_name": "GitHub",
  "username":  "me@example.com",
  "password":  "hunter2",
  "site_url":  "https://github.com",
  "favorite":  true,
  "category":  "Work",
  "notes":     "Personal account"
}
```

`site_name`, `username`, and `password` are required. `site_url`, `favorite`, `category`, and `notes` are optional. `category` is limited to 64 characters.

**Response** — the newly created account, with HTTP `201 Created`.

---

#### `PUT /accounts/{id}`
Updates an existing account entry. All fields must be sent — partial updates are not supported.

**Request body** — same shape as `POST /accounts`.

**Response** — the updated account.

---

#### `POST /accounts/{id}/used`
Marks an account as used by setting `last_used_at` to the current server time. Requires an active session and CSRF token.

**Response** - the updated metadata-only account, including `favorite`, `category`, `last_used_at`, and `password_updated_at`.

---

#### `DELETE /accounts/{id}`
Deletes an account entry.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Account deleted successfully."
  }
}
```

---

### Backups

#### `POST /backup`
Exports all accounts to a timestamped encrypted JSON file in `BACKUP_DIR` (`/var/app/backups` in Docker). The returned filename is not a public download URL.

**Request body**
```json
{
  "backup_password": "separate-backup-password"
}
```

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Encrypted backup created successfully.",
    "filename": "backup_1_20260101_120000.json",
    "count": 5
  }
}
```

---

#### `POST /backup/restore`
Restores accounts from a previously exported encrypted backup file. Send the file as `multipart/form-data` with `backup_file` and `backup_password`.

Entries that are missing required fields are skipped rather than aborting the whole restore. The response tells you how many were imported and how many were skipped.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Restore completed.",
    "imported": 5,
    "skipped": 0,
    "errors": []
  }
}
```

---

#### `GET /backup/history`
Lists all backups made by the current user, newest first.

**Response**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "filename": "backup_1_20260101_120000.json",
      "created_at": "2026-01-01 12:00:00",
      "restored_at": null
    }
  ]
}
```

---

### Generator

#### `POST /generator/password`
Generates a password without storing it.

**Request body**
```json
{
  "length": 20,
  "uppercase": true,
  "lowercase": true,
  "numbers": true,
  "symbols": true
}
```

`length` must be between 8 and 128, and at least one character group must be enabled.

---

## Testing with Postman

### Setup

1. Open Postman and create a new **Collection** called `Password Manager`
2. Add a **Collection Variable**: `base_url` = `http://localhost:8000/backend/api`
3. Go to **Settings → General** and make sure **"Automatically follow redirects"** is on
4. Go to **Settings → General** and make sure **"Send cookies"** is on — this is essential for session-based auth to work across requests

### Recommended test sequence

**Auth flow**
| # | Method | URL | Body |
|---|---|---|---|
| 1 | `GET` | `{{base_url}}/health` | — |
| 2 | `POST` | `{{base_url}}/auth/register` | `{"username":"alice","password":"your-master-password"}` |
| 3 | `POST` | `{{base_url}}/auth/login` | `{"username":"alice","password":"..."}` |
| 4 | `GET` | `{{base_url}}/auth/status` | — |

**CRUD flow** (must be logged in first)
| # | Method | URL | Body |
|---|---|---|---|
| 5 | `POST` | `{{base_url}}/accounts` | `{"site_name":"GitHub","username":"me@example.com","password":"hunter2"}` |
| 6 | `GET` | `{{base_url}}/accounts` | — |
| 7 | `GET` | `{{base_url}}/accounts/1` | — |
| 8 | `GET` | `{{base_url}}/accounts?search=git` | — |
| 9 | `PUT` | `{{base_url}}/accounts/1` | `{"site_name":"GitHub","username":"me@example.com","password":"newpass","site_url":"https://github.com"}` |
| 10 | `DELETE` | `{{base_url}}/accounts/1` | — |

**Change master password** (optional; must be logged in)
| # | Method | URL | Body |
|---|---|---|---|
| 11 | `POST` | `{{base_url}}/auth/password` | `{"current_password":"...","new_password":"..."}` |

**Backup flow**
| # | Method | URL | Notes |
|---|---|---|---|
| 12 | `POST` | `{{base_url}}/backup` | Creates a backup file |
| 13 | `GET` | `{{base_url}}/backup/history` | Lists all backups |
| 14 | `POST` | `{{base_url}}/backup/restore` | `form-data`, key: `backup_file`, value: select the JSON file |

**Logout**
| # | Method | URL |
|---|---|---|
| 15 | `POST` | `{{base_url}}/auth/logout` |
| 16 | `GET` | `{{base_url}}/auth/status` | Should return `authenticated: false` |

---

## Browser Extension

The `browser-extension/` folder contains a local Chrome Manifest V3 click-to-fill extension.

To load it:

1. Start the stack with `docker compose up -d --build`.
2. Log in to the web app at `http://localhost:3000`.
3. Open `chrome://extensions`, enable Developer mode, and choose **Load unpacked**.
4. Select the `browser-extension` folder.

The extension lists matching entries for the current domain and only fetches a plaintext password after the user clicks **Fill**. See `browser-extension/README.md` for the manual test flow and security notes.

## Configuration Reference

| File | Constant | Default | Description |
|---|---|---|---|
| `config/database.php` | `DB_HOST` | `podatkovna-baza` | MySQL hostname — must match `hostname:` in `docker-compose.yml` |
| `config/database.php` | `DB_NAME` | `password_manager` | Database name — created by running `schema.sql` |
| `config/database.php` | `DB_USER` | from `.env` | Application database user |
| `config/database.php` | `DB_PASSWORD` | from `.env` | Application database password |
| `config/app.php` | `SESSION_TIMEOUT_MINUTES` | `15` | Default inactivity timeout before auto-logout |
| `config/app.php` | `HASH_ALGO` | `PASSWORD_BCRYPT` | Algorithm used to hash the master password |
| `config/app.php` | `APP_SECRET` | from `.env` | Server-side pepper; must be strong and stable |
| `config/app.php` | `APP_ENV` | `development` | Change to `production` before any deployment |
| `config/app.php` | `BACKUP_DIR` | `/var/app/backups` in Docker | Server-side backup storage outside the web root |
| `config/app.php` | `BACKUP_MAX_BYTES` | `2097152` in Docker | Maximum accepted encrypted backup upload size |
| `config/app.php` | `BACKUP_MAX_ACCOUNTS` | `1000` | Maximum number of account entries accepted in one restore |
| `config/app.php` | `JSON_REQUEST_MAX_BYTES` | `1048576` in Docker | Maximum JSON request body size for API writes |
| `index.php` | `Allow-Origin` | `http://localhost:3000` | React dev server origin — update if your frontend runs on a different port |

Production notes:
- Set `APP_ENV=production`.
- Set a stable, random `APP_SECRET` of at least 32 characters.
- Put the app behind HTTPS so cookies, recovery flows, and vault operations are never sent over plaintext HTTP.
- Keep `ALLOWED_ORIGINS` limited to the real frontend origin.
- Keep backup storage outside the web root and mounted as a server-side volume.
- Run dependency checks before release: `npm.cmd run audit:ci` in `data/www/frontend` and `docker compose run --rm phpunit composer audit`.

