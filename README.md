# Password Manager

A college-level password manager built with React, PHP, and MySQL — containerised with Docker. This document covers everything you need to get the project running locally and understand how the backend is structured.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Getting Started](#getting-started)
3. [Initialising the Database](#initialising-the-database)
4. [Project Structure](#project-structure)
5. [Backend API](#backend-api)
6. [Testing with Postman](#testing-with-postman)
7. [Configuration Reference](#configuration-reference)

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

### 2. Start the containers

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

### 3. Stop the containers

```bash
docker compose down
```

To also wipe all stored database data:

```bash
docker compose down -v
```

---

## Initialising the Database

The database schema is not created automatically — you need to run `schema.sql` once after the containers are up.

1. Go to **http://localhost:8001** (phpMyAdmin)
2. Log in with username `root` and password `superVarnoGeslo`
3. Click the **SQL** tab at the top
4. Paste the entire contents of `schema.sql` and click **Go**

This creates the `password_manager` database with three tables (`users`, `accounts`, `backups`) and inserts a test user for development.

### Test user credentials

A seed user is included in `schema.sql` for local development and Postman testing. Before using it, you need to generate a valid password hash for it:

1. Create a temporary file `data/www/backend/genhash.php` with this content:
   ```php
   <?php
   echo password_hash('your-chosen-password', PASSWORD_BCRYPT);
   ```
2. Open **http://localhost:8000/backend/genhash.php** in a browser and copy the output
3. In phpMyAdmin, run:
   ```sql
   UPDATE users SET master_password_hash = '<paste hash here>' WHERE username = 'testuser';
   ```
4. Delete `genhash.php`

> **Important:** Remove the seed user row from `schema.sql` before any kind of deployment.

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
            │   │   ├── AuthController.php    # login, logout, status
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
            ├── backups/            # JSON backup files written here (auto-created on first backup)
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

#### `POST /auth/login`
Verifies the master password and starts a session. No authentication required.

**Request body**
```json
{
  "username": "testuser",
  "password": "your-password"
}
```

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Login successful.",
    "username": "testuser"
  }
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

### Accounts

All account endpoints require an active session. Passwords are AES-256-CBC encrypted at rest — the encryption key is derived from the master password hash stored in the session and never touches the database.

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
      "password": "hunter2",
      "notes": null,
      "created_at": "2026-01-01 12:00:00",
      "updated_at": "2026-01-01 12:00:00"
    }
  ]
}
```

---

#### `GET /accounts/{id}`
Returns a single account entry.

**Response** — same shape as a single item from the list above. Returns `404` if the account does not exist or belongs to a different user.

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
  "notes":     "Personal account"
}
```

`site_name`, `username`, and `password` are required. `site_url` and `notes` are optional.

**Response** — the newly created account, with HTTP `201 Created`.

---

#### `PUT /accounts/{id}`
Updates an existing account entry. All fields must be sent — partial updates are not supported.

**Request body** — same shape as `POST /accounts`.

**Response** — the updated account.

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
Exports all accounts to a timestamped JSON file in `backend/backups/`. Passwords are decrypted into the file so the backup is portable.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Backup created successfully.",
    "filename": "backup_1_20260101_120000.json",
    "count": 5
  }
}
```

---

#### `POST /backup/restore`
Restores accounts from a previously exported backup file. Send the file as `multipart/form-data` with the field name `backup_file`.

Entries that are missing required fields are skipped rather than aborting the whole restore. The response tells you how many were imported and how many were skipped.

**Response**
```json
{
  "success": true,
  "data": {
    "message": "Restore completed.",
    "imported": 5,
    "skipped": 0
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
| 2 | `POST` | `{{base_url}}/auth/login` | `{"username":"testuser","password":"..."}` |
| 3 | `GET` | `{{base_url}}/auth/status` | — |

**CRUD flow** (must be logged in first)
| # | Method | URL | Body |
|---|---|---|---|
| 4 | `POST` | `{{base_url}}/accounts` | `{"site_name":"GitHub","username":"me@example.com","password":"hunter2"}` |
| 5 | `GET` | `{{base_url}}/accounts` | — |
| 6 | `GET` | `{{base_url}}/accounts/1` | — |
| 7 | `GET` | `{{base_url}}/accounts?search=git` | — |
| 8 | `PUT` | `{{base_url}}/accounts/1` | `{"site_name":"GitHub","username":"me@example.com","password":"newpass","site_url":"https://github.com"}` |
| 9 | `DELETE` | `{{base_url}}/accounts/1` | — |

**Backup flow**
| # | Method | URL | Notes |
|---|---|---|---|
| 10 | `POST` | `{{base_url}}/backup` | Creates a backup file |
| 11 | `GET` | `{{base_url}}/backup/history` | Lists all backups |
| 12 | `POST` | `{{base_url}}/backup/restore` | `form-data`, key: `backup_file`, value: select the JSON file |

**Logout**
| # | Method | URL |
|---|---|---|
| 13 | `POST` | `{{base_url}}/auth/logout` |
| 14 | `GET` | `{{base_url}}/auth/status` | Should return `authenticated: false` |

---

## Configuration Reference

| File | Constant | Default | Description |
|---|---|---|---|
| `config/database.php` | `DB_HOST` | `podatkovna-baza` | MySQL hostname — must match `hostname:` in `docker-compose.yml` |
| `config/database.php` | `DB_NAME` | `password_manager` | Database name — created by running `schema.sql` |
| `config/database.php` | `DB_USER` | `root` | Database user |
| `config/database.php` | `DB_PASSWORD` | `superVarnoGeslo` | Must match `MYSQL_ROOT_PASSWORD` in `docker-compose.yml` |
| `config/app.php` | `SESSION_TIMEOUT_MINUTES` | `15` | Default inactivity timeout before auto-logout |
| `config/app.php` | `HASH_ALGO` | `PASSWORD_BCRYPT` | Algorithm used to hash the master password |
| `config/app.php` | `APP_ENV` | `development` | Change to `production` before any deployment |
| `index.php` | `Allow-Origin` | `http://localhost:3000` | React dev server origin — update if your frontend runs on a different port |