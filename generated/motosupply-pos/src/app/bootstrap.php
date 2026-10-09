<?php
declare(strict_types=1);

/*
 * Application bootstrap: constants, autoloading, configuration, error handling.
 * Every entry point (index.php, install/index.php, tests) includes this file.
 */

if (!defined('MOTO_ROOT')) {
    define('MOTO_ROOT', dirname(__DIR__));
}
const MOTO_VERSION = '1.3.0';
const MOTO_MIN_PHP = '8.1.0';
const MOTO_SCHEMA_VERSION = 2;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = MOTO_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require MOTO_ROOT . '/app/helpers.php';

// PHP itself works in UTC; the shop timezone is applied explicitly by App\Core\Clock.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    App\Core\Logger::error('Unhandled exception', $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $debug = App\Core\Config::get('app.debug', false) === true;
    $wantsJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json');
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => $debug ? $e->getMessage() : 'An unexpected error occurred. Please try again.',
        ]);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:4rem auto;padding:0 1rem">'
        . '<h1 style="font-size:1.4rem">Something went wrong</h1>'
        . '<p>An unexpected error occurred. Please go back and try again. '
        . 'If the problem continues, contact the system administrator.</p>';
    if ($debug) {
        echo '<pre style="white-space:pre-wrap;background:#f4f4f5;padding:1rem">'
            . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8')
            . '</pre>';
    }
    echo '</div>';
});
