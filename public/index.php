<?php

declare(strict_types=1);

use SecurityFlow\Web\DashboardController;
use SecurityFlow\Web\HttpError;
use SecurityFlow\Web\ModelCatalog;
use SecurityFlow\Web\RequestValidator;
use SecurityFlow\Web\Response;

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PHP 8.2 or later is required.';
    return;
}

require_once dirname(__DIR__) . '/src/Web/bootstrap.php';

ini_set('display_errors', '0');

try {
    RequestValidator::local($_SERVER);
    RequestValidator::querySize($_SERVER);
    if (($_SERVER['PATH_INFO'] ?? '') !== '') {
        throw new HttpError(404, 'The dashboard route does not exist.');
    }
    $body = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > RequestValidator::MAX_BODY) {
            throw new HttpError(413, 'The request body exceeds 4 KiB.');
        }
        $body = file_get_contents('php://input', length: RequestValidator::MAX_BODY + 1);
        if ($body === false) {
            throw new HttpError(400, 'Could not read the request body.');
        }
    }

    if (!extension_loaded('session')) {
        throw new HttpError(503, 'The PHP session extension is required for dashboard runs.');
    }
    if (session_status() !== PHP_SESSION_NONE) {
        throw new HttpError(503, 'The dashboard requires session.auto_start to be disabled.');
    }
    session_name('STATEAXIOM_DASHBOARD');
    $prefix = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $cookiePath = preg_match('/\A\/[A-Za-z0-9_\/.-]*\z/', $prefix) === 1 ? rtrim($prefix, '/') . '/' : '/';
    if (!@session_start([
        'use_strict_mode' => 1,
        'use_cookies' => 1,
        'use_only_cookies' => 1,
        'use_trans_sid' => 0,
        'cookie_httponly' => 1,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
        'cookie_path' => $cookiePath,
        'cookie_domain' => '',
        'cookie_lifetime' => 0,
        'cache_limiter' => '',
    ])) {
        throw new HttpError(503, 'Could not start the local dashboard session.');
    }

    $catalog = new ModelCatalog(dirname(__DIR__) . '/examples');
    $response = (new DashboardController($catalog))->dispatch($_SERVER, $_GET, $_SESSION, $body);
    if (!@session_write_close()) {
        throw new HttpError(503, 'Could not persist the local dashboard session.');
    }
} catch (HttpError $error) {
    $response = Response::json(['error' => $error->getMessage()], $error->status, $error->headers);
} catch (Throwable) {
    $response = Response::json(['error' => 'The dashboard could not complete the request.'], 500);
}

$response->send();
