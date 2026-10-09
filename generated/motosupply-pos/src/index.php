<?php
declare(strict_types=1);

/*
 * MotoSupply POS — front controller. Every page and API call goes through here:
 *   index.php?r=<route>
 * Query-string routing needs no mod_rewrite, so it works on any Apache/PHP host.
 */

define('MOTO_ROOT', __DIR__);
require MOTO_ROOT . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Session;

if (!Config::exists()) {
    Http::redirect(Http::basePath() . '/install/');
}
Config::load();
if (Config::get('app.force_https', false) === true && !Http::isHttps()) {
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    Http::redirect('https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
}

Http::securityHeaders();
Session::start();

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\PosController;
use App\Controllers\ProductController;
use App\Controllers\ReportController;
use App\Controllers\SalesController;
use App\Controllers\SettingsController;

// route => [controller, method, allowed HTTP methods, options]
$routes = [
    'login' => [AuthController::class, 'login', ['GET', 'POST'], ['guest' => true]],
    'logout' => [AuthController::class, 'logout', ['POST'], ['allow_pw_change' => true]],
    'password.change' => [AuthController::class, 'changePassword', ['GET', 'POST'], ['allow_pw_change' => true]],

    'dashboard' => [DashboardController::class, 'index', ['GET'], []],

    'pos' => [PosController::class, 'index', ['GET'], []],
    'api.products.search' => [PosController::class, 'search', ['GET'], ['json' => true]],
    'api.sales.checkout' => [PosController::class, 'checkout', ['POST'], ['json' => true]],
    'sales.receipt' => [PosController::class, 'receipt', ['GET'], []],

    'products' => [ProductController::class, 'index', ['GET'], []],
    'products.create' => [ProductController::class, 'form', ['GET'], []],
    'products.edit' => [ProductController::class, 'form', ['GET'], []],
    'products.save' => [ProductController::class, 'save', ['POST'], []],
    'products.archive' => [ProductController::class, 'archive', ['POST'], []],
    'products.restore' => [ProductController::class, 'restore', ['POST'], []],
    'products.adjust' => [ProductController::class, 'adjust', ['GET', 'POST'], []],
    'products.movements' => [ProductController::class, 'movements', ['GET'], []],

    'sales' => [SalesController::class, 'index', ['GET'], []],
    'sales.view' => [SalesController::class, 'show', ['GET'], []],
    'sales.void' => [SalesController::class, 'void', ['POST'], []],

    'reports' => [ReportController::class, 'index', ['GET'], []],
    'reports.export' => [ReportController::class, 'export', ['GET'], []],

    'settings' => [SettingsController::class, 'index', ['GET'], []],
    'settings.save' => [SettingsController::class, 'save', ['POST'], []],
    'settings.password' => [SettingsController::class, 'password', ['POST'], []],
    'settings.system' => [SettingsController::class, 'system', ['GET'], []],
    'settings.migrate' => [SettingsController::class, 'migrate', ['POST'], []],
];

$route = Http::query('r', 'dashboard');
if (!isset($routes[$route])) {
    http_response_code(404);
    App\Core\View::render('pages/error', ['title' => 'Not found', 'message' => 'The page you requested does not exist.'], Auth::check() ? 'layout/app' : 'layout/guest');
    exit;
}
[$controller, $action, $methods, $opt] = $routes[$route];
$isJson = !empty($opt['json']);

if (!in_array(Http::method(), $methods, true)) {
    http_response_code(405);
    header('Allow: ' . implode(', ', $methods));
    exit('Method not allowed');
}

// Authentication is enforced here, on the server, for every route except login.
if (empty($opt['guest'])) {
    if (!Auth::check()) {
        if ($isJson) {
            Http::json(['ok' => false, 'error' => 'Your session has expired. Please log in again.', 'login' => true], 401);
        }
        Http::redirect(url('login'));
    }
    if ((int) Auth::user()['must_change_password'] === 1 && empty($opt['allow_pw_change'])) {
        if ($isJson) {
            Http::json(['ok' => false, 'error' => 'You must change your password first.'], 403);
        }
        Http::redirect(url('password.change'));
    }
}

// CSRF protection for every state-changing request.
if (Http::isPost() && !Csrf::check()) {
    if ($isJson) {
        Http::json(['ok' => false, 'error' => 'Your session token is invalid. Reload the page and try again.'], 403);
    }
    http_response_code(403);
    App\Core\View::render('pages/error', [
        'title' => 'Session expired',
        'message' => 'The form has expired or was submitted from another site. Go back, reload the page and try again.',
    ], Auth::check() ? 'layout/app' : 'layout/guest');
    exit;
}

(new $controller())->$action();
