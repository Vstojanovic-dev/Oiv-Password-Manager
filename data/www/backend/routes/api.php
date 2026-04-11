<?php

// ---------------------------------------------------------------------------
//   GET    /api/health           → health check (no auth needed)
//   POST   /api/auth/login       → AuthController::login()
//   POST   /api/auth/logout      → AuthController::logout()
//   GET    /api/auth/status      → AuthController::status()
//   GET    /api/accounts         → AccountController::index()
//   GET    /api/accounts/{id}    → AccountController::show()
//   POST   /api/accounts         → AccountController::store()
//   PUT    /api/accounts/{id}    → AccountController::update()
//   DELETE /api/accounts/{id}    → AccountController::destroy()
//   POST   /api/backup           → BackupController::create()
//   POST   /api/backup/restore   → BackupController::restore()
//   GET    /api/backup/history   → BackupController::history()
// ---------------------------------------------------------------------------

Auth::startSession();

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip any leading subdir + /api prefix, e.g. /backend/api or just /api
$path = preg_replace('#^(/[^/]+)?/api#', '', $uri);
$path = rtrim($path, '/');

// Split path into segments, e.g. "/accounts/42" → ['accounts', '42']
$segments = array_values(array_filter(explode('/', $path)));

// ── Health check ─────────────────────────────────────────────────────────────
if ($method === 'GET' && empty($segments)) {
    Response::success([
        'app'     => APP_NAME,
        'version' => APP_VERSION,
        'status'  => 'ok',
    ]);
}

if ($method === 'GET' && ($segments[0] ?? '') === 'health') {
    // Optionally ping the DB to confirm connectivity
    try {
        Database::getInstance()->query('SELECT 1');
        $dbStatus = 'ok';
    } catch (Throwable $e) {
        $dbStatus = 'unreachable';
    }

    Response::success([
        'api'      => 'ok',
        'database' => $dbStatus,
    ]);
}

// ── Auth routes ───────────────────────────────────────────────────────────────
if (($segments[0] ?? '') === 'auth') {
    require_once __DIR__ . '/../src/controllers/AuthController.php';
    $auth = new AuthController();

    if ($method === 'POST' && ($segments[1] ?? '') === 'login') {
        $auth->login();
    }

    if ($method === 'POST' && ($segments[1] ?? '') === 'logout') {
        $auth->logout();
    }

    if ($method === 'GET' && ($segments[1] ?? '') === 'status') {
        $auth->status();
    }

    Response::error('Auth route not found.', 404);
}

// ── Account routes ────────────────────────────────────────────────────────────
if (($segments[0] ?? '') === 'accounts') {
    require_once __DIR__ . '/../src/controllers/AccountController.php';
    $controller = new AccountController();
    $id = isset($segments[1]) ? (int) $segments[1] : null;

    if ($method === 'GET'    && $id === null) { $controller->index();        }
    if ($method === 'GET'    && $id !== null) { $controller->show($id);      }
    if ($method === 'POST'   && $id === null) { $controller->store();        }
    if ($method === 'PUT'    && $id !== null) { $controller->update($id);    }
    if ($method === 'DELETE' && $id !== null) { $controller->destroy($id);   }

    Response::error('Account route not found.', 404);
}

// ── Backup routes ─────────────────────────────────────────────────────────────
if (($segments[0] ?? '') === 'backup') {
    require_once __DIR__ . '/../src/controllers/BackupController.php';
    $controller = new BackupController();

    if ($method === 'POST' && !isset($segments[1]))                          { $controller->create();  }
    if ($method === 'POST' && ($segments[1] ?? '') === 'restore')            { $controller->restore(); }
    if ($method === 'GET'  && ($segments[1] ?? '') === 'history')            { $controller->history(); }

    Response::error('Backup route not found.', 404);
}


Response::error('Route not found.', 404);