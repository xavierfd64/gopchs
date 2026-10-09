<?php
declare(strict_types=1);

/*
 * Updater tests (P12). Runs against a throwaway COPY of src/ because updates write application
 * files, and against its own database.
 *
 *   MOTO_TEST_DB=motosupply_upd php tests/updater_test.php
 *
 * Packages are signed with a throwaway test key that only this copy trusts (config
 * update_trusted_keys); the release key is not needed.
 */

$project = dirname(__DIR__);
$work = sys_get_temp_dir() . '/moto-upd-' . bin2hex(random_bytes(4));
mkdir($work);
exec('cp -a ' . escapeshellarg("$project/src") . ' ' . escapeshellarg("$work/app-root"));
define('MOTO_ROOT', "$work/app-root");
putenv('MOTO_TEST_DB=' . (getenv('MOTO_TEST_DB') ?: 'motosupply_upd'));
require __DIR__ . '/harness.php';

use App\Core\Config;
use App\Core\DB;
use App\Services\Backup;
use App\Services\Updater;

$keys = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keys);
$public = base64_encode(sodium_crypto_sign_publickey($keys));
file_put_contents("$work/test.key", base64_encode($secret));
Config::load(['db' => $db, 'app' => ['debug' => true], 'update_trusted_keys' => [$public]]);

// Business data and private files that an update must never touch.
$p1 = product(['sku' => 'KEEP-1', 'stock_qty' => '42']);
file_put_contents(MOTO_ROOT . '/config/config.php', "<?php return ['secret' => 'do-not-touch'];\n");
@mkdir(MOTO_ROOT . '/uploads/products', 0755, true);
file_put_contents(MOTO_ROOT . '/uploads/products/photo.jpg', 'JPEGDATA');
$fingerprint = static fn (): string => md5(json_encode([
    DB::all('SELECT id, sku, stock_qty, selling_price FROM products ORDER BY id'),
    DB::all('SELECT id, username, password_hash, role_id FROM users ORDER BY id'),
    DB::all('SELECT COUNT(*) FROM sales'),
    file_get_contents(MOTO_ROOT . '/config/config.php'),
    file_get_contents(MOTO_ROOT . '/uploads/products/photo.jpg'),
]));
$before = $fingerprint();

$build = static function (string $version, array $extra = []) use ($project, $work): string {
    $out = "$work/pkg-" . bin2hex(random_bytes(3)) . '.zip';
    $src = MOTO_ROOT;
    if ($extra) {
        $src = "$work/src-" . bin2hex(random_bytes(3));
        exec('cp -a ' . escapeshellarg(MOTO_ROOT) . ' ' . escapeshellarg($src));
        foreach ($extra as $rel => $data) {
            @mkdir(dirname("$src/$rel"), 0755, true);
            file_put_contents("$src/$rel", $data);
        }
    }
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, "$project/tools/build-update.php", "--key=$work/test.key", "--src=$src", "--out=$out", "--version=$version"])) . ' 2>&1', $o, $rc);
    if ($rc !== 0) {
        throw new RuntimeException('build failed: ' . implode("\n", $o));
    }
    return $out;
};
/** Build a package by hand (for malicious variants), signed with $signKey. */
$craft = static function (array $manifest, array $entries, ?string $signKey = null) use ($work, $secret): string {
    $out = "$work/craft-" . bin2hex(random_bytes(3)) . '.zip';
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    $zip = new ZipArchive();
    $zip->open($out, ZipArchive::CREATE);
    $zip->addFromString(Updater::MANIFEST, $json);
    $zip->addFromString(Updater::SIGNATURE, base64_encode(sodium_crypto_sign_detached($json, $signKey ?? $secret)));
    foreach ($entries as $name => $data) {
        $zip->addFromString($name, $data);
    }
    $zip->close();
    return $out;
};
$manifest = static fn (array $files, string $version = '1.3.1', array $more = []): array => [
    'product' => 'motosupply-pos', 'type' => 'update', 'version' => $version,
    'files' => array_map(static fn ($d) => hash('sha256', $d), $files),
] + $more;
$version = static fn (): string => preg_match("/const MOTO_VERSION = '([^']+)'/", (string) file_get_contents(MOTO_ROOT . '/app/bootstrap.php'), $m) ? $m[1] : '?';

echo "\nPackage validation\n";
test('A correctly signed newer package passes inspection and lists what changes', function () use ($build) {
    $pkg = $build('1.3.1', ['database/migrations/003_test_addition.php' => "<?php\nreturn static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE IF NOT EXISTS upd_test (id INT PRIMARY KEY) ENGINE=InnoDB'); };\n"]);
    $i = Updater::inspect($pkg);
    eq('1.3.1', $i['version']);
    eq(MOTO_VERSION, $i['from']);
    ok($i['changed'] >= 1, 'bootstrap.php changes');
    eq(['database/migrations/003_test_addition.php'], $i['migrations']);
    $names = [];
    $z = new ZipArchive();
    $z->open($pkg);
    for ($k = 0; $k < $z->numFiles; $k++) {
        $names[] = $z->getNameIndex($k);
    }
    foreach ($names as $n) {
        ok(!preg_match('#^(install/|config/config\.php|storage/(?!\.htaccess)|uploads/(?!\.htaccess))#', $n), "never packaged: $n");
    }
    $GLOBALS['goodPkg'] = $pkg;
});
test('Wrong signing key is rejected', function () use ($craft, $manifest) {
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $files = ['app/x.php' => '<?php // x'];
    throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files), $files, $other)), 'signature is not valid');
});
test('A file modified after signing is rejected (checksum)', function () use ($craft, $manifest) {
    $files = ['app/x.php' => '<?php // original'];
    throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files), ['app/x.php' => '<?php // evil'])), 'checksum');
});
test('Path traversal, absolute and Windows paths are rejected', function () use ($craft, $manifest) {
    foreach (['../evil.php', 'app/../../evil.php', '/etc/evil.php', 'C:/evil.php', 'app\\evil.php'] as $bad) {
        $files = [$bad => '<?php'];
        throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files), $files)), 'unsafe path');
    }
});
test('Symbolic links are rejected', function () use ($work, $manifest, $secret) {
    $dir = "$work/sym";
    mkdir("$dir/app", 0755, true);
    symlink('/etc/passwd', "$dir/app/link.php");
    $m = $manifest(['app/link.php' => '/etc/passwd']);
    $json = json_encode($m, JSON_UNESCAPED_SLASHES);
    file_put_contents("$dir/" . Updater::MANIFEST, $json);
    file_put_contents("$dir/" . Updater::SIGNATURE, base64_encode(sodium_crypto_sign_detached($json, $secret)));
    exec('cd ' . escapeshellarg($dir) . ' && zip -q --symlinks -r ../sym.zip .');
    throws(RuntimeException::class, fn () => Updater::inspect("$work/sym.zip"), 'symbolic link');
});
test('Files not listed in the signed manifest are rejected', function () use ($craft, $manifest) {
    $files = ['app/x.php' => '<?php'];
    throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files), $files + ['app/extra.php' => '<?php'])), 'unlisted');
});
test('A signed package cannot overwrite config, uploads, storage or the installer', function () use ($craft, $manifest) {
    foreach (['config/config.php', 'uploads/products/photo.jpg', 'storage/installed.lock', 'install/index.php', 'evil.php', 'uploads/x.php'] as $target) {
        $files = [$target => 'x'];
        throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files), $files)), 'outside the application code');
    }
});
test('Downgrades and same-version packages are rejected', function () use ($craft, $manifest) {
    $files = ['app/x.php' => '<?php'];
    throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files, '1.2.9'), $files)), 'Downgrades');
    throws(RuntimeException::class, fn () => Updater::inspect($craft($manifest($files, MOTO_VERSION), $files)), 'not newer');
});
test('A fresh-install ZIP or random ZIP is not accepted as an update', function () use ($work) {
    $z = new ZipArchive();
    $z->open("$work/plain.zip", ZipArchive::CREATE);
    $z->addFromString('index.php', '<?php');
    $z->addFromString('app/bootstrap.php', '<?php');
    $z->close();
    throws(RuntimeException::class, fn () => Updater::inspect("$work/plain.zip"), 'manifest or signature missing');
    file_put_contents("$work/notzip.zip", 'hello');
    throws(RuntimeException::class, fn () => Updater::inspect("$work/notzip.zip"), 'not a valid ZIP');
});

echo "\nInstall, backup, rollback\n";
test('Valid update installs files and migrations, keeps data/config/uploads, makes backups', function () use ($before, $fingerprint, $version) {
    $r = Updater::apply($GLOBALS['goodPkg'], 1);
    ok($r['ok'], implode(' | ', $r['log']));
    eq('1.3.1', $version());
    ok((bool) DB::value("SHOW TABLES LIKE 'upd_test'"), 'migration 003 applied');
    eq($before, $fingerprint(), 'products, users, sales, config and uploads unchanged');
    $h = DB::one('SELECT * FROM update_history WHERE id = ?', [$r['history_id']]);
    eq('success', $h['status']);
    $dir = Backup::dir() . '/' . $h['backup_dir'];
    ok(is_file("$dir/files.zip"), 'files backup');
    ok(is_file("$dir/database.sql.gz") || is_file("$dir/database.sql"), 'database backup');
    ok(!is_file(MOTO_ROOT . '/storage/maintenance.flag'), 'maintenance mode ended');
    $GLOBALS['historyId'] = $r['history_id'];
    $GLOBALS['backupDir'] = $dir;
});
test('The database backup restores to an identical copy', function () use ($db) {
    $dump = glob($GLOBALS['backupDir'] . '/database.sql*')[0];
    $copy = $db['name'] . '_restore';
    DB::pdo()->exec("DROP DATABASE IF EXISTS `$copy`");
    DB::pdo()->exec("CREATE DATABASE `$copy`");
    exec('(' . (str_ends_with($dump, '.gz') ? 'zcat' : 'cat') . ' ' . escapeshellarg($dump) . ') | mysql ' . escapeshellarg($copy) . ' 2>&1', $o, $rc);
    eq(0, $rc, implode("\n", $o));
    foreach (['products', 'users', 'sales', 'stock_movements', 'roles', 'settings'] as $t) {
        eq(DB::value("SELECT COUNT(*) FROM `$t`"), DB::value("SELECT COUNT(*) FROM `$copy`.`$t`"), $t);
    }
    eq(DB::value("SELECT stock_qty FROM products WHERE sku = 'KEEP-1'"), DB::value("SELECT stock_qty FROM `$copy`.products WHERE sku = 'KEEP-1'"));
    DB::pdo()->exec("DROP DATABASE `$copy`");
});
test('Rollback restores the previous files and removes files the update added', function () use ($before, $fingerprint, $version) {
    ok(is_file(MOTO_ROOT . '/database/migrations/003_test_addition.php'));
    $r = Updater::rollback($GLOBALS['historyId']);
    eq(MOTO_VERSION, $version());
    ok(!is_file(MOTO_ROOT . '/database/migrations/003_test_addition.php'), 'added migration file removed');
    eq('rolled_back', DB::value('SELECT status FROM update_history WHERE id = ?', [$GLOBALS['historyId']]));
    eq($before, $fingerprint());
});
test('A failing migration triggers automatic restore of the previous files', function () use ($build, $before, $fingerprint, $version) {
    $pkg = $build('1.3.2', ['database/migrations/004_broken.php' => "<?php\nreturn static function (PDO \$pdo): void { throw new RuntimeException('boom'); };\n"]);
    $r = Updater::apply($pkg, 1);
    ok(!$r['ok']);
    ok(str_contains(implode(' ', $r['log']), 'restored automatically'), implode(' | ', $r['log']));
    eq(MOTO_VERSION, $version(), 'previous version back');
    ok(!is_file(MOTO_ROOT . '/database/migrations/004_broken.php'), 'added files removed');
    eq('failed', DB::value('SELECT status FROM update_history WHERE id = ?', [$r['history_id']]));
    eq($before, $fingerprint());
    ok(!is_file(MOTO_ROOT . '/storage/maintenance.flag'));
});

exec('rm -rf ' . escapeshellarg($work));
echo "\n$passed passed, $failed failed\n";
if ($out = getenv('MOTO_TEST_REPORT')) {
    $md = "| Result | Test | Details |\n|---|---|---|\n";
    foreach ($results as [$r, $n, $d]) {
        $md .= "| $r | " . str_replace('|', '\|', $n) . ' | ' . str_replace('|', '\|', $d) . " |\n";
    }
    file_put_contents($out, $md);
}
exit($failed > 0 ? 1 : 0);
