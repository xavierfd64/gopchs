<?php
declare(strict_types=1);

/*
 * Tests for folder preparation (write tests, safe permission fixes) and HTTPS detection.
 * Must run as a NON-root user (root ignores file permissions), e.g.:
 *   runuser -u www-data -- php tests/requirements_test.php <scratch-dir>
 * Scenario setup that needs another owner is done by tests/requirements_setup.sh (as root).
 */

define('MOTO_ROOT', dirname(__DIR__) . '/src');
require MOTO_ROOT . '/app/bootstrap.php';
restore_exception_handler();
restore_error_handler();

use App\Core\Config;
use App\Core\Http;
use App\Services\Requirements;

$base = $argv[1] ?? '';
$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($ok || $why === '' ? '' : "\n        $why") . "\n";
}
function leftovers(string $dir): array
{
    return array_values(array_filter(scandir($dir) ?: [], static fn ($f) => str_starts_with($f, '.moto-write-test-')));
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Run this as a non-root user (root ignores permissions).\n");
    exit(2);
}
Config::load(['app' => []]);

echo "Folder preparation\n";
// A: everything missing -> created, writable, defaults written, no test files left.
$a = "$base/a";
$r = Requirements::prepareDirectories(false, $a);
t('Missing folders are created recursively', is_dir("$a/storage/logs") && is_dir("$a/uploads/products") && $r['storage/logs']['created']);
t('Created folders pass a real write test', $r['storage/logs']['writable'] && $r['uploads/products']['writable']);
t('Created folders use 0755 (never 0777)', (fileperms("$a/storage/logs") & 0777) === 0755 && (fileperms("$a/uploads/products") & 0777) === 0755,
    sprintf('%o', fileperms("$a/storage/logs") & 0777));
t('Safe default files written (.htaccess deny, uploads no-exec)', str_contains((string) @file_get_contents("$a/storage/.htaccess"), 'Require all denied')
    && str_contains((string) @file_get_contents("$a/uploads/.htaccess"), 'RemoveHandler .php'));
t('Write-test files are cleaned up', leftovers("$a/storage/logs") === [] && leftovers("$a/uploads/products") === []);

// B: folders owned by PHP's user but read-only (0555) -> chmod 0755 fixes them.
$b = "$base/b";
$r = Requirements::prepareDirectories(false, $b);
t('Read-only folders owned by PHP are fixed with chmod 755', $r['storage/logs']['writable'] && $r['storage/logs']['fixed'] === 'permissions set to 755'
    && (fileperms("$b/storage/logs") & 0777) === 0755, json_encode($r['storage/logs']));
t('Existing files in a fixed folder are untouched', @file_get_contents("$b/storage/logs/keep.log") === "keep\n");

// C: folders owned by ANOTHER user (like an FTP upload), parent writable -> recreated as PHP's folder.
$c = "$base/c";
$r = Requirements::prepareDirectories(false, $c);
t('Foreign-owned placeholder folders are recreated and pass the write test', $r['storage/logs']['writable'] && $r['storage/logs']['fixed'] === 'folder recreated'
    && $r['uploads/products']['writable'], json_encode($r['storage/logs']));
t('Recreated uploads folder still has its index.html', is_file("$c/uploads/products/index.html"));

// D: foreign-owned folder AND foreign-owned parent -> cannot be fixed; clear instructions, no absolute paths.
$d = "$base/d";
$_SERVER['DOCUMENT_ROOT'] = $base;
$checks = Requirements::check(false, $d);
$logs = array_values(array_filter($checks, static fn ($x) => str_starts_with($x['name'], 'Folder storage/logs/')))[0];
t('Unfixable folder is reported (not OK) after a failed write test', $logs['status'] === Requirements::WARN && str_contains($logs['result'], 'Not writable'), json_encode($logs));
t('Instructions name the folder relative to the website folder', str_contains($logs['action'], '(website folder)/d/storage/logs/')
    && str_contains($logs['action'], 'Recheck Requirements') && str_contains($logs['action'], 'Never use 777'), $logs['action']);
t('No absolute server path is shown', !str_contains(json_encode($checks), $base), 'leaked path');
$st = array_values(array_filter($checks, static fn ($x) => str_starts_with($x['name'], 'Folder storage/ ')))[0];
t('Unwritable storage/ is a blocking failure', $st['status'] === Requirements::FAIL, json_encode($st));
t('Folder left exactly as it was when the fix is impossible', is_dir("$d/storage/logs") && count(glob("$d/storage/.logs-unwritable-*") ?: []) === 0);

// E: write test never overwrites an existing file.
$e = "$base/e";
@mkdir($e, 0755, true);
file_put_contents("$e/data.txt", 'original');
t('Write test passes and leaves existing files alone', Requirements::writeTest($e) && file_get_contents("$e/data.txt") === 'original' && leftovers($e) === []);
t('Write test fails on a missing folder', !Requirements::writeTest("$e/nope"));

echo "\nHTTPS detection\n";
$reset = static function (array $server, array $proxies = []): void {
    foreach (['HTTPS', 'REQUEST_SCHEME', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        unset($_SERVER[$k]);
    }
    $_SERVER = array_merge($_SERVER, $server);
    Config::load(['app' => ['trusted_proxies' => $proxies]]);
};
$reset(['HTTPS' => 'on', 'SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.5']);
t('HTTPS=on is detected', Http::isHttps());
$reset(['HTTPS' => 'off', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.5']);
t('HTTPS=off is not HTTPS', !Http::isHttps());
$reset(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_PROTO' => 'https']);
t('X-Forwarded-Proto from an untrusted client is ignored', !Http::isHttps() && Http::untrustedProxySaysHttps());
$reset(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['10.0.0.0/8']);
t('X-Forwarded-Proto from a trusted proxy (CIDR) is honoured', Http::isHttps() && !Http::untrustedProxySaysHttps());
$reset(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_SSL' => 'on'], ['10.1.2.3']);
t('X-Forwarded-SSL from a trusted proxy (single IP) is honoured', Http::isHttps());
$reset(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '11.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['10.0.0.0/8']);
t('Proxy outside the trusted range is ignored', !Http::isHttps());
$reset(['SERVER_PORT' => '80', 'REMOTE_ADDR' => '2001:db8::7', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['2001:db8::/32']);
t('IPv6 trusted proxy range works', Http::isHttps());
$reset(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.1.2.4'], ['10.0.0.0/8']);
t('Client IP taken from X-Forwarded-For only via trusted proxies', Http::clientIp() === '198.51.100.9', Http::clientIp());
$reset(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
t('Spoofed X-Forwarded-For is ignored without a trusted proxy', Http::clientIp() === '203.0.113.5');
$reset(['HTTPS' => '', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.5']);
$row = Requirements::httpsRow();
t('HTTP shows a Warning (not Failed) with SSL instructions', $row['status'] === Requirements::WARN && str_contains($row['action'], 'Free SSL Certificates') && str_contains($row['action'], 'AutoSSL'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
