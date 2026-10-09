<?php
declare(strict_types=1);

/*
 * MotoSupply POS 1.3 — service-level tests for the 1.3 fixes and features:
 * stock rules (oversale), integrity audit, roles/permissions, void approval PINs, audit log,
 * CSV import, theme, secrets, end-of-day email (with a local SMTP test server).
 *
 *   MOTO_TEST_DB=motosupply_test php tests/run_v13.php
 * (Updater tests live in tests/updater_test.php because they write application files.)
 */

require __DIR__ . '/harness.php';

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Permissions;
use App\Core\Secrets;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Services\EmailReports;
use App\Services\InventoryService;
use App\Services\Mailer;
use App\Services\ProductImport;
use App\Services\SaleService;
use App\Services\StockIntegrity;
use App\Services\Theme;
use App\Services\UserService;
use App\Services\VoidAuthorization;

function sell(int $productId, int $qty, int $userId = 1): array
{
    return SaleService::checkout([['product_id' => $productId, 'quantity' => $qty]], 'none', '0', '9999999', uuid(), $userId);
}
function counts(): array
{
    return [
        (int) DB::value('SELECT COUNT(*) FROM sales'),
        (int) DB::value('SELECT COUNT(*) FROM sale_items'),
        (int) DB::value('SELECT COUNT(*) FROM stock_movements'),
    ];
}
function roleId(string $slug): int
{
    return (int) DB::value('SELECT id FROM roles WHERE slug = ?', [$slug]);
}
function actor(int $id): array
{
    return Auth::load($id) ?? throw new RuntimeException("user $id not active");
}
function makeUser(string $username, string $role, array $overrides = []): int
{
    return UserService::save(0, ['username' => $username, 'full_name' => ucfirst($username), 'role_id' => (string) roleId($role),
        'password' => 'Counter#Pass42', 'password2' => 'Counter#Pass42', 'overrides' => $overrides], actor(1));
}

// ---------------------------------------------------------------------------------------
echo "\nP1 — stock rules (no oversale)\n";
test('Stock 99, sell 100: rejected, nothing saved, stock stays 99', function () {
    $id = product(['stock_qty' => '99']);
    $before = counts();
    throws(ValidationException::class, fn () => sell($id, 100), 'stock');
    eq(99, stock($id));
    eq($before, counts(), 'no sale, line or movement rows were written');
});
test('Stock 99, sell 99: accepted, stock becomes 0', function () {
    $id = product(['stock_qty' => '99']);
    $r = sell($id, 99);
    eq('completed', $r['sale']['status']);
    eq(0, stock($id));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND movement_type = 'sale' AND qty_before = 99 AND qty_after = 0", [$id]));
});
test('Stock 99, restock 1000: stock becomes 1099', function () {
    $id = product(['stock_qty' => '99']);
    $r = InventoryService::adjust($id, 'add', '1000', 'Delivery DR-1001', 1);
    eq(['qty_before' => 99, 'qty_change' => 1000, 'qty_after' => 1099], array_intersect_key($r, ['qty_before' => 1, 'qty_change' => 1, 'qty_after' => 1]));
    eq(1099, stock($id));
});
test('Stock 0, sell 1: rejected', function () {
    $id = product(['stock_qty' => '0']);
    throws(ValidationException::class, fn () => sell($id, 1));
    eq(0, stock($id));
});
test('Multi-product cart with one short line: whole sale rejected, no partial deduction', function () {
    $a = product(['stock_qty' => '5']);
    $b = product(['stock_qty' => '1']);
    $before = counts();
    throws(ValidationException::class, fn () => SaleService::checkout(
        [['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 2]], 'none', '0', '99999', uuid(), 1));
    eq(5, stock($a));
    eq(1, stock($b));
    eq($before, counts());
});
test('Same product on two cart lines is checked as one total', function () {
    $a = product(['stock_qty' => '5']);
    throws(ValidationException::class, fn () => SaleService::checkout(
        [['product_id' => $a, 'quantity' => 3], ['product_id' => $a, 'quantity' => 3]], 'none', '0', '99999', uuid(), 1));
    eq(5, stock($a));
});
test('Zero, negative and fractional quantities are rejected', function () {
    $a = product(['stock_qty' => '5']);
    foreach ([0, -1, '1.5', 'abc'] as $q) {
        throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $a, 'quantity' => $q]], 'none', '0', '99999', uuid(), 1));
    }
    eq(5, stock($a));
});
test('Database guard: negative stock cannot be stored even by a direct UPDATE', function () {
    ok(StockIntegrity::guardEnabled(), 'stock_qty is UNSIGNED after migration 002');
    $a = product(['stock_qty' => '2']);
    throws(PDOException::class, fn () => DB::run('UPDATE products SET stock_qty = stock_qty - 3 WHERE id = ?', [$a]));
    eq(2, stock($a));
});
test('Sales and adjustments refuse to run on non-transactional (MyISAM) tables', function () {
    $a = product(['stock_qty' => '5']);
    DB::pdo()->exec('ALTER TABLE settings ENGINE = MyISAM');
    DB::setPdo(DB::pdo()); // clear the per-request engine cache
    try {
        throws(ValidationException::class, fn () => sell($a, 1), 'do not support transactions');
        throws(ValidationException::class, fn () => InventoryService::adjust($a, 'add', '1', 'x', 1), 'do not support transactions');
        eq(5, stock($a));
    } finally {
        DB::pdo()->exec('ALTER TABLE settings ENGINE = InnoDB');
        DB::setPdo(DB::pdo());
    }
    sell($a, 1);
    eq(4, stock($a));
});
test('Concurrent sales of the last 5 units by 12 cashiers: exactly 5 succeed, no phantom lines', function () use ($db) {
    $id = product(['stock_qty' => '5']);
    $procs = [];
    $outs = [];
    $start = (string) (microtime(true) + 0.8);
    for ($i = 0; $i < 12; $i++) {
        $procs[] = proc_open([PHP_BINARY, __DIR__ . '/concurrent_worker.php', json_encode($db), (string) $id, uuid(), $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outs[] = $pipes;
    }
    $ok = 0;
    foreach ($procs as $i => $p) {
        $out = trim(stream_get_contents($outs[$i][1]));
        stream_get_contents($outs[$i][2]);
        proc_close($p);
        $ok += $out === 'OK' ? 1 : 0;
    }
    eq(5, $ok);
    eq(0, stock($id));
    eq(5, (int) DB::value('SELECT SUM(quantity) FROM sale_items WHERE product_id = ?', [$id]));
    eq(5, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND movement_type = 'sale'", [$id]));
});

echo "\nP1 — integrity audit and corrections\n";
test('Integrity scan of correct data reports no open issues', function () {
    $s = StockIntegrity::scan();
    eq(0, $s['issues'], json_encode(array_map('count', array_filter($s, 'is_array'))));
    ok($s['guard']);
    eq([], $s['non_transactional']);
});
test('Historical damage is detected, never auto-corrected, and fixed only by audited actions', function () {
    // Simulate a 1.2 database: guard off, an oversale that went negative, a phantom sale line.
    DB::pdo()->exec('ALTER TABLE products MODIFY stock_qty INT NOT NULL DEFAULT 0');
    $p = product(['stock_qty' => '3', 'sku' => 'DAMAGED-1']);
    DB::run('UPDATE products SET stock_qty = -2 WHERE id = ?', [$p]); // unrecorded change (oversale)
    $q = product(['stock_qty' => '4', 'sku' => 'PHANTOM-1']);
    $sale = sell($q, 1)['sale'];
    DB::run('INSERT INTO sale_items (sale_id, product_id, product_name, sku, unit, unit_price, unit_cost, quantity, line_total)
             SELECT sale_id, product_id, product_name, sku, unit, unit_price, unit_cost, 2, unit_price * 2 FROM sale_items WHERE sale_id = ? LIMIT 1', [$sale['id']]);
    DB::run('DELETE FROM stock_movements WHERE sale_id = ? AND product_id = ?', [$sale['id'], $q]);
    DB::run('UPDATE products SET stock_qty = 4 WHERE id = ?', [$q]); // the deduction never happened
    $snapshot = DB::all('SELECT id, stock_qty FROM products ORDER BY id');

    $s = StockIntegrity::scan();
    eq(1, count($s['negative']));
    ok(in_array('DAMAGED-1', array_column($s['ledger'], 'sku'), true), 'ledger mismatch detected');
    eq(1, count(array_unique(array_column($s['phantom'], 'id'))), 'phantom sale detected');
    ok(!$s['guard']);
    eq($snapshot, DB::all('SELECT id, stock_qty FROM products ORDER BY id'), 'scan changes nothing');

    throws(ValidationException::class, fn () => StockIntegrity::enableGuard(), 'negative');
    // Correction 1: physical count (recorded as a 'set' adjustment).
    $r = InventoryService::adjust($p, 'set', '1', 'Integrity correction: counted by Jamie', 1);
    eq(-2, $r['qty_before']);
    eq(1, stock($p));
    // Correction 2: phantom sale whose goods were handed over → marked reviewed, sale unchanged.
    StockIntegrity::markReviewed('phantom', (int) $sale['id'], 'Goods were handed over; count checked', 1);
    eq('completed', DB::value('SELECT status FROM sales WHERE id = ?', [$sale['id']]));
    StockIntegrity::enableGuard();
    $s = StockIntegrity::scan();
    eq(0, $s['issues'], json_encode(['neg' => $s['negative'], 'ledger' => $s['ledger'], 'phantom' => $s['phantom']]));
    ok($s['guard']);
    ok($s['history'] >= 0);
});
test('Voiding a sale with a phantom line returns only the stock that was really deducted', function () {
    DB::pdo()->exec('ALTER TABLE products MODIFY stock_qty INT UNSIGNED NOT NULL DEFAULT 0');
    $a = product(['stock_qty' => '10']);
    $b = product(['stock_qty' => '10']);
    $sale = SaleService::checkout([['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 3]], 'none', '0', '99999', uuid(), 1)['sale'];
    // Damage: B's deduction never happened (as on MyISAM after a failed statement).
    DB::run('DELETE FROM stock_movements WHERE sale_id = ? AND product_id = ?', [$sale['id'], $b]);
    DB::run('UPDATE products SET stock_qty = 10 WHERE id = ?', [$b]);
    SaleService::void((int) $sale['id'], 'Integrity correction', 1, 1);
    eq(10, stock($a), 'A: 2 deducted, 2 returned');
    eq(10, stock($b), 'B: nothing deducted, nothing returned');
});

echo "\nP2 — roles, permissions, users\n";
test('Roles are seeded and administrators hold every permission', function () {
    $slugs = array_column(UserService::roles(), 'slug');
    foreach (['administrator', 'cashier', 'inventory', 'reports', 'custom'] as $r) {
        ok(in_array($r, $slugs, true), "role $r");
    }
    eq(Permissions::all(), actor(1)['permissions']);
});
test('Cashier role: can sell, cannot manage products, users, settings or approve voids', function () {
    $id = makeUser('cashier1', 'cashier');
    $p = actor($id)['permissions'];
    foreach (['pos.access', 'pos.sell'] as $x) {
        ok(in_array($x, $p, true), "has $x");
    }
    foreach (['products.manage', 'users.manage', 'settings.manage', 'sales.void.approve', 'system.update', 'audit.view'] as $x) {
        ok(!in_array($x, $p, true), "lacks $x");
    }
});
test('Per-user overrides add and remove permissions', function () {
    $id = makeUser('cashier2', 'cashier', ['reports.view' => 'allow', 'pos.sell' => 'deny']);
    $p = actor($id)['permissions'];
    ok(in_array('reports.view', $p, true));
    ok(!in_array('pos.sell', $p, true));
    ok(in_array('pos.access', $p, true));
});
test('Unknown permission names are ignored (deny by default)', function () {
    $id = makeUser('cashier3', 'cashier', ['superpower' => 'allow']);
    DB::run('INSERT INTO user_permissions (user_id, permission, allowed) VALUES (?, ?, 1)', [$id, 'everything']);
    ok(!in_array('superpower', actor($id)['permissions'], true));
    ok(!in_array('everything', actor($id)['permissions'], true));
});
test('No self-escalation: own role and permissions cannot be changed', function () {
    $mgr = makeUser('manager1', 'inventory', ['users.manage' => 'allow']);
    $me = actor($mgr);
    UserService::save($mgr, ['username' => 'manager1', 'full_name' => 'M', 'role_id' => (string) roleId('administrator'),
        'overrides' => ['system.update' => 'allow']], $me);
    $after = actor($mgr);
    eq('inventory', $after['role_slug'], 'role unchanged');
    ok(!in_array('system.update', $after['permissions'], true), 'no new permission');
});
test('Non-admins cannot grant permissions they lack or assign the Administrator role', function () {
    $me = actor((int) DB::value("SELECT id FROM users WHERE username = 'manager1'"));
    throws(ValidationException::class, fn () => UserService::save(0, ['username' => 'newadmin', 'role_id' => (string) roleId('administrator'),
        'password' => 'Counter#Pass42', 'password2' => 'Counter#Pass42'], $me), 'Administrator');
    throws(ValidationException::class, fn () => UserService::save(0, ['username' => 'newcustom', 'role_id' => (string) roleId('custom'),
        'overrides' => ['settings.manage' => 'allow'], 'password' => 'Counter#Pass42', 'password2' => 'Counter#Pass42'], $me), 'hold yourself');
    throws(ValidationException::class, fn () => UserService::save(1, ['username' => 'admin', 'role_id' => (string) roleId('cashier')], $me), 'administrator');
});
test('Last active administrator cannot be deactivated or demoted', function () {
    $other = makeUser('cashier4', 'cashier');
    throws(ValidationException::class, fn () => UserService::setActive(1, false, actor(1)), 'own');
    $admin2 = makeUser('admin2', 'administrator');
    UserService::setActive(1, false, actor($admin2)); // allowed: admin2 remains
    try {
        // admin2 is now the last active administrator: it cannot deactivate or demote itself.
        throws(ValidationException::class, fn () => UserService::setActive($admin2, false, actor($admin2)), 'own');
        UserService::save($admin2, ['username' => 'admin2', 'role_id' => (string) roleId('cashier')], actor($admin2));
        eq('administrator', actor($admin2)['role_slug'], 'own role is never changed through the form');
        throws(ValidationException::class, fn () => UserService::setActive($admin2, false, actor($other)), 'administrator');
        // With the guard check itself: demoting the only active admin is refused even for an admin actor.
        throws(ValidationException::class, fn () => UserService::save($admin2, ['username' => 'admin2', 'role_id' => (string) roleId('cashier')],
            ['id' => 0, 'role_slug' => 'administrator', 'permissions' => Permissions::all()]), 'last active administrator');
    } finally {
        DB::run('UPDATE users SET is_active = 1 WHERE id = 1');
    }
});
test('Deactivated users are logged out on their next request and cannot log in', function () {
    $id = makeUser('cashier5', 'cashier');
    UserService::setActive($id, false, actor(1));
    eq(null, Auth::load($id));
    eq('invalid', Auth::attempt('cashier5', 'Counter#Pass42', '10.0.0.5'));
});
test('Password reset gives a one-time temporary password and forces a change', function () {
    $id = makeUser('cashier6', 'cashier');
    $temp = UserService::resetPassword($id, actor(1));
    ok(strlen($temp) >= 12);
    eq(1, (int) DB::value('SELECT must_change_password FROM users WHERE id = ?', [$id]));
    ok(password_verify($temp, (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$id])));
    ok(!str_contains(json_encode(DB::all('SELECT * FROM audit_log')), $temp), 'temporary password never stored in the audit log');
});
test('New user passwords must be strong and must not contain the username', function () {
    throws(ValidationException::class, fn () => UserService::save(0, ['username' => 'weakling', 'role_id' => (string) roleId('cashier'),
        'password' => 'password1', 'password2' => 'password1'], actor(1)));
    throws(ValidationException::class, fn () => UserService::save(0, ['username' => 'jamie', 'role_id' => (string) roleId('cashier'),
        'password' => 'Jamie#2026x', 'password2' => 'Jamie#2026x'], actor(1)));
});

echo "\nP3 — void approval\n";
test('Void PIN policy rejects short, non-numeric, repeated and sequential PINs', function () {
    foreach (['12345', 'abcdef', '111111', '123456', '987654', '0123456789', str_repeat('5', 13)] as $bad) {
        ok(VoidAuthorization::pinPolicyError($bad) !== null, "rejects $bad");
    }
    foreach (['482915', '70315562'] as $good) {
        eq(null, VoidAuthorization::pinPolicyError($good), "accepts $good");
    }
});
test('PIN is stored only as a hash, separate from the login password', function () {
    VoidAuthorization::setPin(1, '482915');
    $row = DB::one('SELECT password_hash, void_pin_hash FROM users WHERE id = 1');
    ok(!str_contains((string) $row['void_pin_hash'], '482915'));
    ok(password_verify('482915', (string) $row['void_pin_hash']));
    ok($row['void_pin_hash'] !== $row['password_hash']);
    throws(ValidationException::class, fn () => VoidAuthorization::verify('admin', 'admin', '10.1.1.1'), 'Approval failed');
});
test('Approver needs the approve permission and a correct PIN', function () {
    $cashier = makeUser('cashier7', 'cashier');
    VoidAuthorization::setPin($cashier, '561902');
    throws(ValidationException::class, fn () => VoidAuthorization::verify('cashier7', '561902', '10.1.1.2'), 'Approval failed');
    throws(ValidationException::class, fn () => VoidAuthorization::verify('admin', '000001', '10.1.1.2'));
    throws(ValidationException::class, fn () => VoidAuthorization::verify('nobody', '482915', '10.1.1.2'));
    eq(1, VoidAuthorization::verify('admin', '482915', '10.1.1.2')['id']);
});
test('Approval is rate-limited per approver (5 failures lock it for 15 minutes)', function () {
    $sup = makeUser('supervisor1', 'administrator');
    VoidAuthorization::setPin($sup, '730194');
    for ($i = 0; $i < 5; $i++) {
        throws(ValidationException::class, fn () => VoidAuthorization::verify('supervisor1', '000000', '10.2.0.' . $i));
    }
    throws(ValidationException::class, fn () => VoidAuthorization::verify('supervisor1', '730194', '10.2.0.99'), 'locked');
    DB::run("UPDATE pin_attempts SET attempted_at = DATE_SUB(attempted_at, INTERVAL 16 MINUTE) WHERE approver_id = ?", [$sup]);
    eq($sup, VoidAuthorization::verify('supervisor1', '730194', '10.2.0.99')['id']);
});
test('Approval is rate-limited per IP address (15 failures)', function () {
    for ($i = 0; $i < 15; $i++) {
        throws(ValidationException::class, fn () => VoidAuthorization::verify('ghost' . $i, '123987', '10.3.3.3'));
    }
    throws(ValidationException::class, fn () => VoidAuthorization::verify('admin', '482915', '10.3.3.3'), 'locked');
    eq(1, VoidAuthorization::verify('admin', '482915', '10.3.3.4')['id']);
});
test('Void records requester, approver, reason and time; keeps the sale; cannot repeat', function () {
    $a = product(['stock_qty' => '10']);
    $sale = sell($a, 4)['sale'];
    $cashier = (int) DB::value("SELECT id FROM users WHERE username = 'cashier7'");
    SaleService::void((int) $sale['id'], 'Customer returned the item', $cashier, 1);
    $v = SaleService::find((int) $sale['id']);
    eq('voided', $v['status']);
    eq($cashier, (int) $v['voided_by']);
    eq(1, (int) $v['void_approved_by']);
    eq('Customer returned the item', $v['void_reason']);
    ok($v['voided_at'] !== null);
    eq(1, count($v['items']), 'original lines kept');
    eq(10, stock($a));
    throws(ValidationException::class, fn () => SaleService::void((int) $sale['id'], 'again', $cashier, 1), 'already been voided');
    eq(10, stock($a), 'stock restored once');
    throws(ValidationException::class, fn () => SaleService::void((int) sell($a, 1)['sale']['id'], '   ', $cashier, 1), 'reason');
});

echo "\nP14 — audit log\n";
test('Audit entries record actor, action, record, status and never secrets', function () {
    Audit::log('test.secret', 'user', 7, ['pin' => '482915', 'password' => 'Hunter#22', 'smtp_password' => 'x', 'nested' => ['token' => 'abc'], 'reason' => 'ok'], 'failure',
        ['id' => 1, 'username' => 'admin']);
    $row = DB::one("SELECT * FROM audit_log WHERE action = 'test.secret'");
    eq('admin', $row['username']);
    eq('user', $row['entity_type']);
    eq('7', (string) $row['entity_id']);
    eq('failure', $row['status']);
    foreach (['482915', 'Hunter#22', 'abc'] as $secret) {
        ok(!str_contains((string) $row['details'], $secret), "secret $secret not stored");
    }
    ok(str_contains((string) $row['details'], 'ok'));
});

echo "\nP10 — CSV import\n";
$csv = static function (string $content): string {
    $f = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($f, $content);
    return $f;
};
test('Templates: with-example has one EXAMPLE- row; headers-only has none', function () use ($csv) {
    $with = ProductImport::parse($csv(ProductImport::template(true)));
    $without = ProductImport::parse($csv(ProductImport::template(false)));
    eq(1, count($with));
    ok(str_starts_with($with[0]['sku'], 'EXAMPLE-'));
    eq(0, count($without));
});
test('Example row is never imported', function () use ($csv) {
    $a = ProductImport::analyse(ProductImport::parse($csv(ProductImport::template(true))), 'create');
    eq(['create' => 0, 'update' => 0, 'skip' => 1, 'error' => 0], $a['counts']);
    eq(['created' => 0, 'updated' => 0], ProductImport::apply($a, 1));
    eq(null, DB::value("SELECT id FROM products WHERE sku LIKE 'EXAMPLE-%'"));
});
test('Preview finds row errors and duplicate SKUs/barcodes without writing anything', function () use ($csv) {
    $before = (int) DB::value('SELECT COUNT(*) FROM products');
    $rows = ProductImport::parse($csv("name,sku,barcode,selling_price,stock_qty\n"
        . "Brake pad,IMP-1,111,250.00,5\n"
        . "Brake pad copy,IMP-1,222,250.00,5\n"          // duplicate SKU in file
        . "Chain,IMP-2,111,300.00,2\n"                   // duplicate barcode in file
        . ",IMP-3,,abc,1\n"                              // missing name, bad price
        . "Spark plug,IMP-4,,90,-3\n"));                 // bad stock
    $a = ProductImport::analyse($rows, 'create');
    eq(1, $a['counts']['create']);
    eq(4, $a['counts']['error']);
    eq($before, (int) DB::value('SELECT COUNT(*) FROM products'));
});
test('Create mode skips existing SKUs; stock and prices of existing products are untouched', function () use ($csv) {
    $id = product(['sku' => 'EXIST-1', 'stock_qty' => '7', 'selling_price' => '100.00']);
    $a = ProductImport::analyse(ProductImport::parse($csv("name,sku,selling_price,stock_qty\nRenamed,EXIST-1,999.00,500\nNew item,NEW-1,50.00,3\n")), 'create');
    eq(['create' => 1, 'update' => 0, 'skip' => 1, 'error' => 0], $a['counts']);
    eq(['created' => 1, 'updated' => 0], ProductImport::apply($a, 1));
    eq(7, stock($id));
    eq('100.00', DB::value('SELECT selling_price FROM products WHERE id = ?', [$id]));
    eq(3, stock((int) DB::value("SELECT id FROM products WHERE sku = 'NEW-1'")));
});
test('Update mode changes details/prices only, keeps missing columns, never stock; lists changes', function () use ($csv) {
    $id = (int) DB::value("SELECT id FROM products WHERE sku = 'EXIST-1'");
    DB::run("UPDATE products SET description = 'Keep me' WHERE id = ?", [$id]);
    $a = ProductImport::analyse(ProductImport::parse($csv("name,sku,selling_price,stock_qty\nRenamed,EXIST-1,120.00,500\n")), 'update');
    eq(1, $a['counts']['update']);
    $ch = $a['rows'][0]['changes'];
    eq(['100.00', '120.00'], $ch['selling_price']);
    ok(isset($ch['name']) && !isset($ch['description']) && !isset($ch['stock_qty']));
    ProductImport::apply($a, 1);
    $p = DB::one('SELECT name, selling_price, stock_qty, description FROM products WHERE id = ?', [$id]);
    eq(['Renamed', '120.00', 7, 'Keep me'], [$p['name'], $p['selling_price'], (int) $p['stock_qty'], $p['description']]);
    $again = ProductImport::analyse(ProductImport::parse($csv("name,sku,selling_price\nRenamed,EXIST-1,120\n")), 'update');
    eq(1, $again['counts']['skip'], 'unchanged row is skipped');
});
test('Import is all-or-nothing: a failure part-way writes no rows', function () use ($csv) {
    $a = ProductImport::analyse(ProductImport::parse($csv("name,sku,barcode,selling_price\nOne,TXN-1,,10\nTwo,TXN-2,BC-TXN,10\n")), 'create');
    eq(2, $a['counts']['create']);
    product(['sku' => 'OTHER-BC', 'barcode' => 'BC-TXN']); // conflicting change after the preview
    throws(Throwable::class, fn () => ProductImport::apply($a, 1));
    eq(null, DB::value("SELECT id FROM products WHERE sku = 'TXN-1'"), 'first row rolled back');
});
test('Import reads semicolon CSV, Windows-1252 text and our own formula-escaped exports', function () use ($csv) {
    $rows = ProductImport::parse($csv(mb_convert_encoding("Product name;SKU;Price\nPi\xC3\xB1on gear;ENC-1;'=1+1\n", 'Windows-1252', 'UTF-8')));
    eq('Piñon gear', $rows[0]['name']);
    eq('=1+1', $rows[0]['selling_price']);
    $a = ProductImport::analyse($rows, 'create');
    eq(1, $a['counts']['error'], 'formula is not a valid price');
});

echo "\nP13 — theme\n";
test('Theme colours are validated for format and contrast', function () {
    eq(null, Theme::validate('#dd4a2b', '#1f2024'));
    ok(Theme::validate('red', '#1f2024') !== null);
    ok(Theme::validate('#ffff00', '#1f2024') !== null, 'yellow primary has too little contrast with white');
    eq(null, Theme::validate('#1d4ed8', '#f4f4f5'));
    ok(Theme::contrast(Theme::bestText('#f4f4f5'), '#f4f4f5') >= 4.5);
});
test('Theme CSS reflects saved colours and contains only validated values', function () {
    Settings::set(['theme_primary' => '#1d4ed8', 'theme_sidebar' => '#0f172a']);
    $css = Theme::css();
    ok(str_contains($css, '#1d4ed8') && str_contains($css, '#0f172a'));
    Settings::set(['theme_primary' => '#dd4a2b;}body{display:none', 'theme_sidebar' => '#1f2024']);
    ok(!str_contains(Theme::css(), 'display:none'), 'invalid stored value ignored');
    Settings::set(['theme_primary' => Theme::DEFAULT_PRIMARY, 'theme_sidebar' => Theme::DEFAULT_SIDEBAR]);
});

echo "\nSecrets\n";
test('Secrets are encrypted at rest and decrypt only with the same key', function () {
    $enc = Secrets::encrypt('smtp-Pa55word!');
    ok(!str_contains($enc, 'smtp-Pa55word!'));
    eq('smtp-Pa55word!', Secrets::decrypt($enc));
    eq(null, Secrets::decrypt('s1:' . base64_encode(random_bytes(60))));
});

echo "\nP11 — end-of-day email\n";
$sink = null;
// MOTO_TEST_SMTP_SINK="port:/dir" uses a sink that is already running (dir holds cert.pem and mail/),
// e.g. started on the host for a container without Python. Otherwise one is started here.
if (preg_match('/^(\d+):(.+)$/', (string) getenv('MOTO_TEST_SMTP_SINK'), $ext)) {
    [$smtpPort, $sinkDir] = [(int) $ext[1], $ext[2]];
} else {
    $sinkDir = sys_get_temp_dir() . '/moto-smtp-' . bin2hex(random_bytes(4));
    mkdir($sinkDir);
    $smtpPort = 2525 + random_int(0, 400);
    exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" -keyout '
        . escapeshellarg("$sinkDir/key.pem") . ' -out ' . escapeshellarg("$sinkDir/cert.pem") . ' 2>/dev/null');
    $sink = proc_open(['python3', __DIR__ . '/smtp_sink.py', (string) $smtpPort, "$sinkDir/mail", "$sinkDir/cert.pem", "$sinkDir/key.pem"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $sinkPipes);
    fgets($sinkPipes[1]); // "ready"
}
Mailer::$testStreamOptions = ['cafile' => "$sinkDir/cert.pem"];
Settings::set([
    'mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $smtpPort, 'smtp_encryption' => 'tls',
    'smtp_username' => 'reports@shop.test', 'smtp_password_enc' => Secrets::encrypt('Smtp#Secret9'),
    'mail_from_address' => 'reports@shop.test', 'mail_from_name' => 'MotoSupply', 'email_recipients' => 'owner@shop.test, manager@shop.test',
    'email_enabled' => '1', 'email_time' => '00:00', 'email_sections' => 'summary,low_stock,out_of_stock,top_products',
    'email_attach_pdf' => '1', 'email_attach_csv' => '1',
]);
$mails = static fn (): array => glob("$sinkDir/mail/*.eml") ?: [];
test('Test email is delivered over STARTTLS with SMTP authentication', function () use ($mails, $sinkDir) {
    $n = count($mails());
    Mailer::send(['owner@shop.test'], 'MotoSupply test', 'Hello', '<p>Hello</p>');
    eq($n + 1, count($mails()));
    eq('reports@shop.test:Smtp#Secret9:tls=True', file_get_contents("$sinkDir/mail/" . ($n + 1) . '.auth'));
});
test('SMTP password is never sent unencrypted to a remote server', function () {
    Settings::set(['smtp_encryption' => 'none', 'smtp_host' => 'mail.example.invalid']);
    try {
        throws(RuntimeException::class, fn () => Mailer::send(['owner@shop.test'], 's', 't', 'h'));
    } finally {
        Settings::set(['smtp_encryption' => 'tls', 'smtp_host' => '127.0.0.1']);
    }
});
test('Report totals match the database; voided sales are excluded', function () {
    $a = product(['stock_qty' => '50', 'selling_price' => '150.00']);
    sell($a, 2);
    SaleService::checkout([['product_id' => $a, 'quantity' => 1]], 'amount', '10.00', '99999', uuid(), 1);
    SaleService::void((int) sell($a, 5)['sale']['id'], 'Wrong item', 1, 1);
    $today = Clock::withTimezone(EmailReports::timezone(), static fn () => Clock::todayLocal());
    [$from, $to] = Clock::withTimezone(EmailReports::timezone(), static fn () => Clock::localRangeToUtc($today, $today));
    $r = EmailReports::build($today);
    $db = DB::one("SELECT COUNT(*) AS n, SUM(total) AS net, SUM(discount_amount) AS disc FROM sales WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$from, $to]);
    eq((int) $db['n'], $r['sum']['txn_count']);
    eq((int) round((float) $db['net'] * 100), $r['sum']['net']);
    eq((int) round((float) $db['disc'] * 100), $r['sum']['discounts']);
    ok($r['sum']['void_count'] >= 1);
});
test('Scheduled report is sent once per date with PDF and CSV attachments', function () use ($mails) {
    $date = EmailReports::dueDate();
    ok($date !== null, 'due (send time 00:00)');
    $n = count($mails());
    eq('sent', EmailReports::runScheduled('cron'));
    eq('already-sent', EmailReports::runScheduled('visit'));
    eq('already-sent', EmailReports::runScheduled('url'));
    eq($n + 1, count($mails()), 'exactly one email');
    $eml = file_get_contents($mails()[$n] ?? end($mails()));
    ok(str_contains($eml, 'X-Envelope-To: <owner@shop.test>,<manager@shop.test>'));
    preg_match_all('/filename="([^"]+)"/', $eml, $m);
    eq(3, count($m[1]), 'two PDFs and one CSV');
    // Attachments decode to a real PDF and CSV.
    preg_match_all('/Content-Type: (application\/pdf|text\/csv)[^\r\n]*\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition[^\r\n]*\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $eml, $parts, PREG_SET_ORDER);
    foreach ($parts as $p) {
        $data = base64_decode(preg_replace('/\s+/', '', $p[2]), true);
        ok($p[1] === 'application/pdf' ? str_starts_with($data, '%PDF-') && str_contains($data, '%%EOF') : str_contains($data, 'Transaction'), $p[1] . ' decodes');
    }
    eq('sent', DB::value('SELECT status FROM email_report_runs WHERE report_date = ?', [$date]));
});
test('Report is not due before the send time, and disabled means nothing is sent', function () {
    Settings::set(['email_time' => '23:59']);
    $tz = new DateTimeZone(EmailReports::timezone());
    eq(null, EmailReports::dueDate(new DateTimeImmutable('today 10:00', $tz)));
    eq((new DateTimeImmutable('yesterday', $tz))->format('Y-m-d'), EmailReports::dueDate(new DateTimeImmutable('today 23:59:30', $tz)));
    Settings::set(['email_time' => '00:00', 'email_enabled' => '0']);
    eq('disabled', EmailReports::runScheduled('cron'));
    Settings::set(['email_enabled' => '1']);
});
test('Failures are recorded without secrets, retried at most 3 times automatically', function () {
    $date = '2026-01-15';
    Settings::set(['smtp_port' => '1']); // nothing listens: connection fails
    for ($i = 0; $i < 3; $i++) {
        ok(str_starts_with(EmailReports::runScheduled('cron', $date), 'failed: Could not connect'));
    }
    ok(str_starts_with(EmailReports::runScheduled('cron', $date), 'gave-up'));
    $run = DB::one('SELECT * FROM email_report_runs WHERE report_date = ?', [$date]);
    eq('failed', $run['status']);
    eq(3, (int) $run['attempts']);
    $everything = json_encode(DB::all('SELECT * FROM email_report_runs')) . json_encode(DB::all('SELECT * FROM audit_log'))
        . implode('', array_map('file_get_contents', glob(MOTO_ROOT . '/storage/logs/*.log') ?: []));
    ok(!str_contains($everything, 'Smtp#Secret9'), 'SMTP password not logged');
});
test('A manual "Send now" may retry a failed date and then sends it once', function () use ($mails) {
    $date = '2026-01-15';
    Settings::set(['smtp_port' => (string) $GLOBALS['smtpPort']]);
    $n = count($mails());
    eq('sent', EmailReports::runScheduled('manual', $date));
    eq('already-sent', EmailReports::runScheduled('manual', $date));
    eq($n + 1, count($mails()));
});
if ($sink !== null) {
    proc_terminate($sink);
    proc_close($sink);
    exec('rm -rf ' . escapeshellarg($sinkDir));
}

echo "\n$passed passed, $failed failed\n";
if ($out = getenv('MOTO_TEST_REPORT')) {
    $md = "| Result | Test | Details |\n|---|---|---|\n";
    foreach ($results as [$r, $n, $d]) {
        $md .= "| $r | " . str_replace('|', '\|', $n) . ' | ' . str_replace('|', '\|', $d) . " |\n";
    }
    file_put_contents($out, $md);
}
exit($failed > 0 ? 1 : 0);
