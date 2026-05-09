<?php

Auth::startSession();

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$path = preg_replace('#^(/[^/]+)?/api#', '', $uri);
$path = rtrim($path, '/');
$segments = array_values(array_filter(explode('/', $path)));
$csrfExempt = ($segments[0] ?? '') === 'auth'
    && (
        in_array(($segments[1] ?? ''), ['register', 'login'], true)
        || (($segments[1] ?? '') === 'forgot' && in_array(($segments[2] ?? ''), ['verify', 'reset'], true))
    );

if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && !$csrfExempt) {
    Auth::requireCsrf();
}

if ($method === 'GET' && empty($segments)) {
    Response::success([
        'app' => APP_NAME,
        'version' => APP_VERSION,
        'status' => 'ok',
    ]);
}

if ($method === 'GET' && ($segments[0] ?? '') === 'health') {
    try {
        Database::getInstance()->query('SELECT 1');
        $dbStatus = 'ok';
    } catch (Throwable) {
        $dbStatus = 'unreachable';
    }

    Response::success([
        'api' => 'ok',
        'database' => $dbStatus,
    ]);
}

if (($segments[0] ?? '') === 'auth') {
    require_once __DIR__ . '/../src/controllers/AuthController.php';
    $auth = new AuthController();

    if ($method === 'GET' && ($segments[1] ?? '') === 'csrf') {
        $auth->csrf();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'register') {
        $auth->register();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'login') {
        $auth->login();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'password') {
        $auth->changePassword();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'recovery-codes') {
        $auth->recoveryCodes();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'forgot' && ($segments[2] ?? '') === 'verify') {
        $auth->forgotVerify();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'forgot' && ($segments[2] ?? '') === 'reset') {
        $auth->forgotReset();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'logout') {
        $auth->logout();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'logout-all') {
        $auth->logoutAll();
    }
    if ($method === 'GET' && ($segments[1] ?? '') === 'security-events') {
        $auth->securityEvents();
    }
    if ($method === 'GET' && ($segments[1] ?? '') === 'sessions') {
        $auth->sessions();
    }
    if ($method === 'DELETE' && ($segments[1] ?? '') === 'sessions' && isset($segments[2])) {
        $auth->revokeSession((int) $segments[2]);
    }
    if ($method === 'GET' && ($segments[1] ?? '') === 'status') {
        $auth->status();
    }

    Response::error('Auth route not found.', 404);
}

if (($segments[0] ?? '') === 'accounts') {
    require_once __DIR__ . '/../src/controllers/AccountController.php';
    $controller = new AccountController();
    $id = isset($segments[1]) ? (int) $segments[1] : null;

    if ($method === 'GET' && $id === null) {
        $controller->index();
    }
    if ($method === 'GET' && ($segments[1] ?? '') === 'password-report') {
        $controller->passwordReport();
    }
    if ($method === 'GET' && isset($segments[1]) && $segments[1] === 'trash') {
        $controller->trash();
    }
    if ($method === 'GET' && $id !== null && ($segments[2] ?? '') === 'password') {
        $controller->password($id);
    }
    if ($method === 'GET' && $id !== null && ($segments[2] ?? '') === 'history') {
        $controller->passwordHistory($id);
    }
    if ($method === 'POST' && $id !== null && ($segments[2] ?? '') === 'restore') {
        $controller->restore($id);
    }
    if ($method === 'DELETE' && $id !== null && ($segments[2] ?? '') === 'permanent') {
        $controller->permanentlyDelete($id);
    }
    if ($method === 'GET' && $id !== null) {
        $controller->show($id);
    }
    if ($method === 'POST' && $id === null) {
        $controller->store();
    }
    if ($method === 'POST' && $id !== null && ($segments[2] ?? '') === 'used') {
        $controller->markUsed($id);
    }
    if ($method === 'PUT' && $id !== null) {
        $controller->update($id);
    }
    if ($method === 'DELETE' && $id !== null) {
        $controller->destroy($id);
    }

    Response::error('Account route not found.', 404);
}

if (($segments[0] ?? '') === 'backup') {
    require_once __DIR__ . '/../src/controllers/BackupController.php';
    $controller = new BackupController();

    if ($method === 'POST' && !isset($segments[1])) {
        $controller->create();
    }
    if ($method === 'POST' && ($segments[1] ?? '') === 'restore') {
        $controller->restore();
    }
    if ($method === 'GET' && ($segments[1] ?? '') === 'history') {
        $controller->history();
    }

    Response::error('Backup route not found.', 404);
}

if (($segments[0] ?? '') === 'generator') {
    require_once __DIR__ . '/../src/controllers/GeneratorController.php';
    $controller = new GeneratorController();

    if ($method === 'POST' && ($segments[1] ?? '') === 'password') {
        $controller->password();
    }

    Response::error('Generator route not found.', 404);
}

Response::error('Route not found.', 404);
