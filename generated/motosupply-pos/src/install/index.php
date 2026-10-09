<?php
declare(strict_types=1);

/*
 * MotoSupply POS — installation wizard.
 * Steps: welcome → requirements → database → shop → admin → install → done.
 * Once installed, the wizard is permanently locked (storage/installed.lock + config file)
 * and never creates, resets or overwrites accounts or configuration.
 */

define('MOTO_ROOT', dirname(__DIR__));
require MOTO_ROOT . '/app/bootstrap.php';
require __DIR__ . '/lib/Installer.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Session;
use App\Services\Requirements;

Config::load([]); // no configuration yet
Requirements::ensureDirectories();
Http::securityHeaders();
Session::start();

const STEPS = [
    'welcome' => 'Welcome',
    'requirements' => 'Requirements',
    'database' => 'Database',
    'shop' => 'Shop',
    'admin' => 'Administrator',
    'install' => 'Install',
    'done' => 'Finished',
];

function wizard_url(string $step): string
{
    return Http::basePath() . '/install/index.php?step=' . rawurlencode($step);
}

function view(string $name, array $data = []): never
{
    $data['step'] ??= $name;
    extract($data, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/views/' . $name . '.php';
    $content = ob_get_clean();
    include __DIR__ . '/views/layout.php';
    exit;
}

$w = &$_SESSION['wizard'];
$w = is_array($w) ? $w : [];
$step = Http::query('step', 'welcome');
if (!isset(STEPS[$step])) {
    $step = 'welcome';
}

// ---- Finished page: shown once, right after a successful install in this browser session.
if ($step === 'done' && !empty($_SESSION['install_done'])) {
    $done = $_SESSION['install_done'];
    unset($_SESSION['install_done']);
    view('done', ['loginUrl' => $done['login'], 'username' => $done['username'], 'configPrivate' => $done['private']]);
}

// ---- Locked: never run again once installed.
if (Installer::isInstalled()) {
    http_response_code(403);
    view('locked', ['step' => 'done']);
}

$errors = [];
$notice = null;

if (Http::isPost()) {
    if (!Csrf::check()) {
        $errors['_form'] = 'This page expired. Please try again.';
    } else {
        $action = Http::post('action');
        switch ($step) {
            case 'requirements':
                if (!Requirements::hasFailures(Requirements::check(true))) {
                    $w['req_ok'] = true;
                    Http::redirect(wizard_url('database'));
                }
                $errors['_form'] = 'Please fix the failed items before continuing.';
                break;

            case 'database':
                [$db, $errors] = Installer::validateDatabase($_POST, $w['db']['pass'] ?? null);
                $w['db_form'] = array_diff_key($db, ['pass' => 1]);
                $w['db_ok'] = false;
                if (!$errors) {
                    [$pdo, $err] = Installer::connect($db);
                    if ($pdo === null) {
                        $errors['_form'] = $err;
                    } else {
                        $state = Installer::inspect($pdo);
                        if (!$state['ok']) {
                            $errors['_form'] = $state['message'];
                        } elseif ($priv = Installer::privileges($pdo)) {
                            $errors['_form'] = $priv;
                        } else {
                            $w['db'] = $db;
                            $w['db_ok'] = true;
                            if ($action === 'continue') {
                                Http::redirect(wizard_url('shop'));
                            }
                            $notice = $state['message'];
                        }
                    }
                }
                break;

            case 'shop':
                [$shop, $errors] = Installer::validateShop($_POST);
                $w['shop_form'] = $shop;
                if (!$errors) {
                    $w['shop'] = $shop;
                    Http::redirect(wizard_url('admin'));
                }
                break;

            case 'admin':
                [$admin, $errors] = Installer::validateAdmin($_POST);
                $w['admin_form'] = ['admin_user' => $admin['username'], 'admin_name' => $admin['full_name']];
                if (!$errors) {
                    $w['admin'] = $admin;
                    Http::redirect(wizard_url('install'));
                }
                break;

            case 'install':
                if (empty($w['db_ok']) || empty($w['shop']) || empty($w['admin'])) {
                    Http::redirect(wizard_url('database'));
                }
                $err = Installer::install($w['db'], $w['shop'], $w['admin']);
                if ($err === null) {
                    $username = $w['admin']['username'];
                    $private = Installer::$configLocation === 'private';
                    $_SESSION = []; // discard wizard state, including the database password
                    Session::regenerate();
                    $_SESSION['install_done'] = [
                        'login' => Http::basePath() . '/index.php?r=login',
                        'username' => $username,
                        'private' => $private,
                    ];
                    Http::redirect(wizard_url('done'));
                }
                $errors['_form'] = $err;
                break;
        }
    }
}

// ---- Step guards: each step needs the previous ones.
$guards = [
    'database' => !empty($w['req_ok']),
    'shop' => !empty($w['db_ok']),
    'admin' => !empty($w['db_ok']) && !empty($w['shop']),
    'install' => !empty($w['db_ok']) && !empty($w['shop']) && !empty($w['admin']),
    'done' => false,
];
$previous = ['database' => 'requirements', 'shop' => 'database', 'admin' => 'shop', 'install' => 'admin', 'done' => 'welcome'];
if (isset($guards[$step]) && !$guards[$step]) {
    $target = $step;
    while (isset($guards[$target]) && !$guards[$target]) {
        $target = $previous[$target];
    }
    Http::redirect(wizard_url($target));
}

$data = ['errors' => $errors, 'notice' => $notice, 'w' => $w];
switch ($step) {
    case 'welcome':
        view('welcome', $data);
    case 'requirements':
        view('requirements', $data + ['checks' => Requirements::check(true)]);
    case 'database':
        view('database', $data + ['v' => $w['db_form'] ?? ['host' => '', 'port' => 3306, 'name' => '', 'user' => ''], 'hasPassword' => isset($w['db']['pass']) && $w['db']['pass'] !== '']);
    case 'shop':
        view('shop', $data + ['v' => $w['shop_form'] ?? ['shop_name' => 'MotoSupply Shop', 'shop_address' => '', 'shop_phone' => '', 'timezone' => 'Asia/Manila', 'currency_code' => 'PHP']]);
    case 'admin':
        view('admin', $data + ['v' => $w['admin_form'] ?? ['admin_user' => '', 'admin_name' => '']]);
    case 'install':
        view('install', $data);
}
view('welcome', $data);
