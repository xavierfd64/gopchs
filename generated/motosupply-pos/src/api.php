<?php
declare(strict_types=1);

/*
 * MotoSupply POS — JSON API for the cashier desktop app:  api.php?r=<route>
 *
 * No cookies or sessions: requests carry a short-lived bearer token (see ApiTokens).
 * In production mode (Settings → System Check → Require HTTPS) plain HTTP is refused.
 * Only cashier operations exist here; administration stays in the web application.
 */

define('MOTO_ROOT', __DIR__);
require MOTO_ROOT . '/app/bootstrap.php';

use App\Controllers\ApiController;
use App\Core\Config;
use App\Core\Http;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex');

set_exception_handler(static function (\Throwable $e): void {
    App\Core\Logger::error('API error', $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Server error. Please try again.']]);
});

if (!Config::exists()) {
    ApiController::fail('not_installed', 'MotoSupply is not installed on this server yet.', 503);
}
Config::load();
if (is_file(MOTO_ROOT . '/storage/maintenance.flag') && filemtime(MOTO_ROOT . '/storage/maintenance.flag') > time() - 1800) {
    header('Retry-After: 60');
    ApiController::fail('maintenance', 'MotoSupply is being updated. Please try again in a minute.', 503);
}
if (App\Services\Migrator::pending() !== []) {
    // The web application applies database updates (with a backup) on its next page view.
    ApiController::fail('maintenance', 'The server is finishing an update. Ask an administrator to open MotoSupply in a browser, then try again.', 503);
}
if (!Http::isHttps() && Http::httpsRequired()) {
    ApiController::fail('https_required', 'This server only accepts secure (HTTPS) connections.', 403);
}

/* route => [method, HTTP method, needs token] */
$routes = [
    'ping' => ['ping', 'GET', false],
    'auth.login' => ['login', 'POST', false],
    'auth.logout' => ['logout', 'POST', true],
    'auth.me' => ['me', 'GET', true],
    'config' => ['config', 'GET', true],
    'products.search' => ['search', 'GET', true],
    'products.lookup' => ['lookup', 'GET', true],
    'products.stock' => ['stock', 'GET', true],
    'sales.checkout' => ['checkout', 'POST', true],
    'sales.by-token' => ['byToken', 'GET', true],
    'sales.receipt' => ['receipt', 'GET', true],
    'sales.recent' => ['recent', 'GET', true],
];
$route = Http::query('r');
if (!isset($routes[$route])) {
    ApiController::fail('not_found', 'Unknown API endpoint.', 404);
}
[$action, $method, $needsToken] = $routes[$route];
if (Http::method() !== $method) {
    header('Allow: ' . $method);
    ApiController::fail('method_not_allowed', 'Method not allowed.', 405);
}
if ($method === 'POST' && !str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    ApiController::fail('invalid_request', 'Send JSON (Content-Type: application/json).', 415);
}

$api = new ApiController();
if ($needsToken) {
    $api->authenticate();
}
$api->$action();
