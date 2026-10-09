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
if (!Http::isHttps() && Http::httpsRequired()) {
    // Redirect to HTTPS, but never loop: if a proxy terminates SSL without being trusted, or the
    // previous redirect came straight back as "not HTTPS", explain instead of redirecting again.
    $recent = (int) ($_COOKIE['moto_https_redirect'] ?? 0) > time() - 15;
    if (Http::untrustedProxySaysHttps() || $recent) {
        http_response_code(503);
        App\Core\View::render('pages/https_problem', ['title' => 'Secure connection problem', 'proxy' => Http::untrustedProxySaysHttps()], 'layout/guest');
        exit;
    }
    setcookie('moto_https_redirect', (string) time(), ['expires' => time() + 60, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    Http::redirect('https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
}
if (isset($_COOKIE['moto_https_redirect']) && Http::isHttps()) {
    setcookie('moto_https_redirect', '', ['expires' => time() - 3600, 'path' => '/']);
}

Http::securityHeaders();
Session::start();

use App\Controllers\AccountController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\ImportController;
use App\Controllers\IntegrityController;
use App\Controllers\PosController;
use App\Controllers\ProductController;
use App\Controllers\ReportController;
use App\Controllers\SalesController;
use App\Controllers\SettingsController;
use App\Controllers\ThemeController;
use App\Controllers\UpdateController;
use App\Controllers\UserController;

// Maintenance mode (set while a browser update is being applied).
if (is_file(MOTO_ROOT . '/storage/maintenance.flag') && filemtime(MOTO_ROOT . '/storage/maintenance.flag') > time() - 1800) {
    http_response_code(503);
    header('Retry-After: 60');
    App\Core\View::render('pages/error', ['title' => 'Updating', 'message' => 'MotoSupply is being updated. Please try again in a minute.'], 'layout/guest');
    exit;
}

// Database schema older than the code (e.g. files updated by FTP): back up and apply the additive
// migrations automatically, once, under a lock.
App\Services\SchemaUpdater::ensureCurrent();

/*
 * route => [controller, method, HTTP methods, options]
 * Options: perm  => permission (string) or any-of (array) required — enforced here, server-side
 *          auth  => true: any signed-in user (own account pages)
 *          guest => true: no login (login page, theme CSS, cron endpoint with its own secret)
 *          json  => true: API endpoint (JSON errors)
 * A route with none of perm/auth/guest is refused (deny by default).
 */
$routes = [
    'login' => [AuthController::class, 'login', ['GET', 'POST'], ['guest' => true]],
    'logout' => [AuthController::class, 'logout', ['POST'], ['auth' => true, 'allow_pw_change' => true]],
    'password.change' => [AuthController::class, 'changePassword', ['GET', 'POST'], ['auth' => true, 'allow_pw_change' => true]],
    'home' => [DashboardController::class, 'home', ['GET'], ['auth' => true]],
    'theme.css' => [ThemeController::class, 'css', ['GET'], ['guest' => true]],
    'cron.daily-report' => [CronController::class, 'dailyReport', ['GET', 'POST'], ['guest' => true]],

    'dashboard' => [DashboardController::class, 'index', ['GET'], ['perm' => 'dashboard.view']],

    'pos' => [PosController::class, 'index', ['GET'], ['perm' => 'pos.access']],
    'api.products.search' => [PosController::class, 'search', ['GET'], ['perm' => 'pos.access', 'json' => true]],
    'api.sales.checkout' => [PosController::class, 'checkout', ['POST'], ['perm' => 'pos.sell', 'json' => true]],
    'sales.receipt' => [PosController::class, 'receipt', ['GET'], ['perm' => ['sales.view', 'pos.sell']]],
    'receipt.test' => [PosController::class, 'testReceipt', ['GET'], ['perm' => ['settings.notifications', 'pos.sell']]],

    'products' => [ProductController::class, 'index', ['GET'], ['perm' => 'inventory.view']],
    'products.create' => [ProductController::class, 'form', ['GET'], ['perm' => 'products.manage']],
    'products.edit' => [ProductController::class, 'form', ['GET'], ['perm' => 'products.manage']],
    'products.save' => [ProductController::class, 'save', ['POST'], ['perm' => 'products.manage']],
    'products.archive' => [ProductController::class, 'archive', ['POST'], ['perm' => 'products.manage']],
    'products.restore' => [ProductController::class, 'restore', ['POST'], ['perm' => 'products.manage']],
    'products.adjust' => [ProductController::class, 'adjust', ['GET', 'POST'], ['perm' => 'inventory.adjust']],
    'products.movements' => [ProductController::class, 'movements', ['GET'], ['perm' => 'inventory.movements']],
    'products.import' => [ImportController::class, 'index', ['GET', 'POST'], ['perm' => 'products.import']],
    'products.import.confirm' => [ImportController::class, 'confirm', ['POST'], ['perm' => 'products.import']],
    'products.import.template' => [ImportController::class, 'template', ['GET'], ['perm' => 'products.import']],

    'inventory.integrity' => [IntegrityController::class, 'index', ['GET'], ['perm' => 'inventory.movements']],
    'inventory.integrity.correct' => [IntegrityController::class, 'correct', ['POST'], ['perm' => 'inventory.adjust']],
    'inventory.integrity.guard' => [IntegrityController::class, 'enableGuard', ['POST'], ['perm' => 'inventory.adjust']],

    'sales' => [SalesController::class, 'index', ['GET'], ['perm' => 'sales.view']],
    'sales.view' => [SalesController::class, 'show', ['GET'], ['perm' => 'sales.view']],
    'sales.void' => [SalesController::class, 'void', ['POST'], ['perm' => 'sales.void']],

    'reports' => [ReportController::class, 'index', ['GET'], ['perm' => 'reports.view']],
    'reports.export' => [ReportController::class, 'export', ['GET'], ['perm' => 'reports.export']],

    'users' => [UserController::class, 'index', ['GET'], ['perm' => 'users.manage']],
    'users.create' => [UserController::class, 'form', ['GET'], ['perm' => 'users.manage']],
    'users.edit' => [UserController::class, 'form', ['GET'], ['perm' => 'users.manage']],
    'users.save' => [UserController::class, 'save', ['POST'], ['perm' => 'users.manage']],
    'users.status' => [UserController::class, 'status', ['POST'], ['perm' => 'users.manage']],
    'users.reset-password' => [UserController::class, 'resetPassword', ['POST'], ['perm' => 'users.manage']],
    'users.clear-pin' => [UserController::class, 'clearPin', ['POST'], ['perm' => 'users.manage']],

    'account' => [AccountController::class, 'index', ['GET'], ['auth' => true]],
    'account.password' => [AccountController::class, 'password', ['POST'], ['auth' => true]],
    'account.pin' => [AccountController::class, 'pin', ['POST'], ['perm' => 'sales.void.approve']],

    'settings' => [SettingsController::class, 'index', ['GET'], ['perm' => ['settings.manage', 'settings.notifications', 'system.update', 'audit.view', 'users.manage']]],
    'settings.save' => [SettingsController::class, 'save', ['POST'], ['perm' => 'settings.manage']],
    'settings.receipt' => [SettingsController::class, 'receipt', ['GET', 'POST'], ['perm' => 'settings.notifications']],
    'settings.email' => [SettingsController::class, 'email', ['GET', 'POST'], ['perm' => 'settings.notifications']],
    'settings.email.test' => [SettingsController::class, 'emailTest', ['POST'], ['perm' => 'settings.notifications']],
    'settings.email.send-now' => [SettingsController::class, 'emailSendNow', ['POST'], ['perm' => 'settings.notifications']],
    'settings.email.cron-key' => [SettingsController::class, 'emailCronKey', ['POST'], ['perm' => 'settings.notifications']],
    'settings.appearance' => [SettingsController::class, 'appearance', ['GET', 'POST'], ['perm' => 'settings.manage']],
    'settings.branding' => [SettingsController::class, 'branding', ['POST'], ['perm' => 'settings.manage']],
    'settings.system' => [SettingsController::class, 'system', ['GET'], ['perm' => 'settings.manage']],
    'settings.migrate' => [SettingsController::class, 'migrate', ['POST'], ['perm' => 'system.update']],
    'settings.security' => [SettingsController::class, 'security', ['POST'], ['perm' => 'settings.manage']],

    'audit' => [AuditController::class, 'index', ['GET'], ['perm' => 'audit.view']],

    'updates' => [UpdateController::class, 'index', ['GET'], ['perm' => 'system.update']],
    'updates.upload' => [UpdateController::class, 'upload', ['POST'], ['perm' => 'system.update']],
    'updates.apply' => [UpdateController::class, 'apply', ['POST'], ['perm' => 'system.update']],
    'updates.rollback' => [UpdateController::class, 'rollback', ['POST'], ['perm' => 'system.update']],
    'updates.backup' => [UpdateController::class, 'downloadBackup', ['GET'], ['perm' => 'system.update']],
];

$route = Http::query('r', 'home');
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

// Authentication and authorization are enforced here, on the server, for every route.
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
    $allowed = isset($opt['perm'])
        ? Auth::canAny((array) $opt['perm'])
        : !empty($opt['auth']); // deny by default when a route declares nothing
    if (!$allowed) {
        App\Core\Audit::log('access.denied', 'route', $route, ['method' => Http::method()], 'failure');
        if ($isJson) {
            Http::json(['ok' => false, 'error' => 'You do not have permission to do this.'], 403);
        }
        http_response_code(403);
        App\Core\View::render('pages/error', ['title' => 'Not allowed', 'message' => 'Your account does not have permission to open this page. Ask an administrator if you need access.']);
        exit;
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

// Optional fallback for hosts without cron: after the page is sent, check whether the daily
// email report is due (it is sent at most once per day; timing depends on someone visiting).
if (Auth::check() && empty($opt['json']) && App\Core\Settings::get('email_on_visit') === '1' && App\Core\Settings::get('email_enabled') === '1') {
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            App\Services\EmailReports::runScheduled('visit');
        } catch (\Throwable $e) {
            App\Core\Logger::error('Visit-triggered email report failed', $e);
        }
    });
}

(new $controller())->$action();
