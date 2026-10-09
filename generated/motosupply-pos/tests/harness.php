<?php
declare(strict_types=1);

/*
 * Shared test harness: assertions, helpers and a freshly migrated throwaway database.
 * Included by tests/run.php and tests/run_v13.php.
 */

if (!defined('MOTO_ROOT')) {
    define('MOTO_ROOT', dirname(__DIR__) . '/src');
}
require MOTO_ROOT . '/app/bootstrap.php';
restore_exception_handler();

use App\Core\Clock;
use App\Core\Config;
use App\Core\DB;
use App\Core\Settings;
use App\Services\Migrator;
use App\Services\ProductService;

$db = [
    'host' => getenv('MOTO_TEST_HOST') ?: 'localhost',
    'port' => (int) (getenv('MOTO_TEST_PORT') ?: 3306),
    'name' => getenv('MOTO_TEST_DB') ?: 'motosupply_test',
    'user' => getenv('MOTO_TEST_USER') ?: 'moto',
    'pass' => getenv('MOTO_TEST_PASS') ?: 'motopass',
];
Config::load(['db' => $db, 'app' => ['debug' => true]]);

$passed = 0;
$failed = 0;
$results = [];
function test(string $name, callable $fn): void
{
    global $passed, $failed, $results;
    try {
        $fn();
        $passed++;
        $results[] = ['PASS', $name, ''];
        echo "  PASS  $name\n";
    } catch (Throwable $e) {
        $failed++;
        $msg = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        $results[] = ['FAIL', $name, $msg];
        echo "  FAIL  $name\n        $msg\n";
    }
}
function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
/** @param class-string<Throwable> $class */
function throws(string $class, callable $fn, ?string $contains = null): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("exception message '{$e->getMessage()}' does not contain '$contains'");
        }
        return $e;
    }
    throw new RuntimeException("expected $class to be thrown");
}
function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function stock(int $id): int
{
    return (int) DB::value('SELECT stock_qty FROM products WHERE id = ?', [$id]);
}
function product(array $over = []): int
{
    static $n = 0;
    $n++;
    $d = ProductService::validate(array_merge([
        'name' => "Test product $n", 'sku' => "T-$n", 'barcode' => '', 'category' => 'Engine Oil',
        'unit' => 'pc', 'cost_price' => '100.00', 'selling_price' => '150.00', 'stock_qty' => '10',
    ], $over), true);
    return ProductService::create($d, 1);
}

// ---------------------------------------------------------------------------------------
echo "Preparing test database {$db['name']}…\n";
$pdo = DB::connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
DB::setPdo($pdo);
(new Migrator($pdo))->migrate();
Settings::reset();
$now = Clock::nowUtc();
DB::run(
    "INSERT INTO users (username, password_hash, full_name, role, role_id, must_change_password, created_at, updated_at)
     VALUES ('admin', ?, 'Admin', 'administrator', (SELECT id FROM roles WHERE slug = 'administrator'), 1, ?, ?)",
    [password_hash('admin', PASSWORD_DEFAULT), $now, $now]
);
$_SESSION = [];
