<?php
declare(strict_types=1);

/*
 * MotoSupply POS — service-level test suite.
 * Runs against a throwaway MySQL/MariaDB database (all tables are dropped first).
 *
 *   MOTO_TEST_DSN_HOST=localhost MOTO_TEST_DB=motosupply_test MOTO_TEST_USER=moto MOTO_TEST_PASS=motopass php tests/run.php
 */

require __DIR__ . '/harness.php';

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\DB;
use App\Core\Money;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Services\InventoryService;
use App\Services\Migrator;
use App\Services\ProductService;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Services\SaleService;


echo "\nMoney & time\n";
test('Money::parse accepts valid amounts and rejects bad ones', function () {
    eq(125050, Money::parse('1,250.50'));
    eq(100, Money::parse('1'));
    eq(10, Money::parse('0.1'));
    eq(null, Money::parse('-5'));
    eq(null, Money::parse('1.234'));
    eq(null, Money::parse('abc'));
    eq(null, Money::parse('1e3'));
});
test('Money formatting uses peso sign and grouping', function () {
    eq('₱1,250.00', Money::format(125000, '₱'));
    eq('-₱0.05', Money::format(-5, '₱'));
    eq('1250.00', Money::toDecimal(125000));
    eq(-350, Money::toCents('-3.50'));
});
test('Percent discount rounds half-up to the centavo', function () {
    eq(1250, Money::percent(10000, 1250)); // 12.5% of 100.00
    eq(17, Money::percent(333, 500));      // 5% of 3.33 = 0.1665 -> 0.17
});
test('Local date range converts Asia/Manila to UTC', function () {
    [$a, $b] = Clock::localRangeToUtc('2026-10-14', '2026-10-14');
    eq('2026-10-13 16:00:00', $a);
    eq('2026-10-14 16:00:00', $b);
});

echo "\nAuthentication\n";
test('Valid login succeeds and invalid login fails', function () {
    $_SESSION = [];
    eq('invalid', Auth::attempt('admin', 'nope', '10.0.0.1'));
    eq('invalid', Auth::attempt('ghost', 'admin', '10.0.0.1'));
    eq('ok', Auth::attempt('admin', 'admin', '10.0.0.1'));
    eq(1, Auth::id());
    eq(1, (int) Auth::user()['must_change_password'], 'temporary password flagged');
});
test('Password is stored only as a bcrypt/argon hash', function () {
    $hash = (string) DB::value("SELECT password_hash FROM users WHERE username = 'admin'");
    ok($hash !== 'admin' && password_get_info($hash)['algo'] !== null, 'hash');
    ok(password_verify('admin', $hash));
});
test('Login is throttled after repeated failures', function () {
    $_SESSION = [];
    for ($i = 0; $i < Auth::MAX_ATTEMPTS; $i++) {
        Auth::attempt('admin', 'bad', '10.9.9.9');
    }
    // Even the correct password is refused while the username is locked, from any IP.
    eq('locked', Auth::attempt('admin', 'admin', '10.1.1.1'));
    DB::run('DELETE FROM login_attempts');
    for ($i = 0; $i < Auth::MAX_IP_ATTEMPTS; $i++) {
        Auth::attempt('user' . $i, 'bad', '10.8.8.8');
    }
    eq('locked', Auth::attempt('admin', 'Moto$hop2026', '10.8.8.8'), 'IP lock after many usernames');
    DB::run('DELETE FROM login_attempts');
});
test('Weak new passwords are rejected', function () {
    ok(Auth::validateNewPassword('short', 'short', 'admin') !== null);
    ok(Auth::validateNewPassword('admin', 'admin', 'admin') !== null);
    ok(Auth::validateNewPassword('Abcdefg123', 'Abcdefg124', 'admin') !== null);
    eq(null, Auth::validateNewPassword('Moto$hop2026', 'Moto$hop2026', 'admin'));
});
test('Password change clears the forced-change flag', function () {
    $_SESSION = ['user_id' => 1];
    Auth::changePassword(1, 'Moto$hop2026');
    eq(0, (int) DB::value('SELECT must_change_password FROM users WHERE id = 1'));
    ok(Auth::verifyCurrentPassword('Moto$hop2026'));
});

echo "\nProducts\n";
$p1 = 0;
test('Product creation records opening stock movement', function () use (&$p1) {
    $p1 = product(['name' => 'Motul 5100 4T 10W-40', 'sku' => 'ms-001', 'barcode' => '4801234567890', 'selling_price' => '450', 'cost_price' => '350', 'stock_qty' => '24']);
    $p = ProductService::find($p1);
    eq('MS-001', $p['sku'], 'SKU normalized to upper case');
    eq('450.00', $p['selling_price']);
    eq(24, (int) $p['stock_qty']);
    eq(1, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND movement_type = 'initial'", [$p1]));
});
test('Duplicate SKU is rejected', function () {
    $e = throws(ValidationException::class, fn () => product(['sku' => 'MS-001']));
    ok(isset($e->errors['sku']));
});
test('Duplicate barcode is rejected; empty barcodes are allowed many times', function () use (&$p1) {
    $e = throws(ValidationException::class, fn () => product(['barcode' => '4801234567890']));
    ok(isset($e->errors['barcode']));
    product(['barcode' => '']);
    product(['barcode' => '']);
    ok(DB::value('SELECT COUNT(*) FROM products WHERE barcode IS NULL') >= 2);
});
test('Invalid prices and quantities are rejected', function () {
    foreach ([['selling_price' => '-1'], ['selling_price' => 'abc'], ['cost_price' => '1.999'], ['stock_qty' => '-3'], ['stock_qty' => '2.5'], ['name' => '']] as $bad) {
        throws(ValidationException::class, fn () => product($bad));
    }
});
test('Editing a product does not change its stock', function () use (&$p1) {
    $d = ProductService::validate(['name' => 'Motul 5100 4T 10W-40 (1L)', 'sku' => 'MS-001', 'barcode' => '4801234567890', 'category' => 'Engine Oil', 'unit' => 'bottle', 'cost_price' => '350', 'selling_price' => '460', 'stock_qty' => '999'], false);
    ProductService::update($p1, $d);
    eq(24, stock($p1));
    eq('460.00', ProductService::find($p1)['selling_price']);
    ProductService::update($p1, ProductService::validate(['name' => 'Motul 5100 4T 10W-40', 'sku' => 'MS-001', 'barcode' => '4801234567890', 'category' => 'Engine Oil', 'unit' => 'bottle', 'cost_price' => '350', 'selling_price' => '450'], false));
});
test('Search finds products by name, SKU, barcode and category', function () use (&$p1) {
    eq($p1, (int) ProductService::searchForPos('4801234567890')['exact']['id'], 'barcode exact');
    eq($p1, (int) ProductService::searchForPos('ms-001')['exact']['id'], 'sku exact, case-insensitive');
    ok(count(ProductService::searchForPos('Motul')['results']) >= 1, 'name');
    ok(count(ProductService::searchForPos('Engine')['results']) >= 1, 'category');
    eq(null, ProductService::searchForPos('0000000000000')['exact'], 'unknown barcode');
    eq([], ProductService::searchForPos('_%_')['results']);
});

echo "\nStock adjustments\n";
test('Adjustment records before/change/after, reason and user', function () use (&$p1) {
    $r = InventoryService::adjust($p1, 'add', '6', 'Delivery received', 1);
    eq(['qty_before' => 24, 'qty_change' => 6, 'qty_after' => 30], $r);
    $m = DB::one("SELECT * FROM stock_movements WHERE product_id = ? AND movement_type = 'adjustment' ORDER BY id DESC LIMIT 1", [$p1]);
    eq('Delivery received', $m['reason']);
    eq(1, (int) $m['user_id']);
    InventoryService::adjust($p1, 'set', '24', 'Physical count', 1);
    eq(24, stock($p1));
});
test('Adjustment cannot make stock negative and requires a reason', function () use (&$p1) {
    throws(ValidationException::class, fn () => InventoryService::adjust($p1, 'remove', '25', 'Damaged', 1));
    throws(ValidationException::class, fn () => InventoryService::adjust($p1, 'add', '1', '  ', 1));
    throws(ValidationException::class, fn () => InventoryService::adjust($p1, 'add', '-1', 'x', 1));
    eq(24, stock($p1));
});

echo "\nPOS sales\n";
$p2 = product(['name' => 'NGK Iridium Spark Plug', 'sku' => 'MS-014', 'barcode' => '4806512384012', 'selling_price' => '620', 'cost_price' => '470', 'stock_qty' => '8', 'category' => 'Spark Plugs']);
$saleId = 0;
test('Successful sale: server prices, totals, change, stock and movements', function () use ($p1, $p2, &$saleId) {
    $res = SaleService::checkout(
        [['product_id' => $p1, 'quantity' => 2], ['product_id' => (string) $p2, 'quantity' => '1']],
        'amount', '100', '2000', uuid(), 1
    );
    $s = $res['sale'];
    $saleId = (int) $s['id'];
    eq(false, $res['duplicate']);
    eq('1520.00', $s['subtotal']);   // 2×450 + 620
    eq('100.00', $s['discount_amount']);
    eq('1420.00', $s['total']);
    eq('2000.00', $s['amount_tendered']);
    eq('580.00', $s['change_due']);
    eq(3, (int) $s['item_count']);
    ok((bool) preg_match('/^MS-\d{6}-[0-9A-F]{6}$/', $s['transaction_no']), 'transaction number format');
    eq(22, stock($p1));
    eq(7, stock($p2));
    eq(2, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE sale_id = ? AND movement_type = 'sale'", [$saleId]));
    eq('350.00', $s['items'][0]['unit_cost'], 'historical cost captured');
});
test('Client-supplied prices and totals are ignored', function () use ($p1) {
    $res = SaleService::checkout([['product_id' => $p1, 'quantity' => 1, 'price' => '0.01', 'total' => '0.01']], 'none', '0', '450', uuid(), 1);
    eq('450.00', $res['sale']['total']);
});
test('Percent discount is calculated on the server', function () use ($p2) {
    $res = SaleService::checkout([['product_id' => $p2, 'quantity' => 1]], 'percent', '10', '558', uuid(), 1);
    eq('62.00', $res['sale']['discount_amount']);
    eq('558.00', $res['sale']['total']);
    eq('0.00', $res['sale']['change_due']);
});
test('Insufficient payment is rejected and nothing is saved', function () use ($p1) {
    $before = (int) DB::value('SELECT COUNT(*) FROM sales');
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 1]], 'none', '0', '449.99', uuid(), 1), 'Insufficient');
    eq($before, (int) DB::value('SELECT COUNT(*) FROM sales'));
    eq(21, stock($p1));
});
test('Insufficient stock is rejected', function () use ($p2) {
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p2, 'quantity' => 7], ['product_id' => $p2, 'quantity' => 1]], 'none', '0', '99999', uuid(), 1), 'only 6 in stock');
    eq(6, stock($p2));
});
test('Discount larger than subtotal and invalid carts are rejected', function () use ($p1) {
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 1]], 'amount', '451', '1000', uuid(), 1));
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 1]], 'percent', '101', '1000', uuid(), 1));
    throws(ValidationException::class, fn () => SaleService::checkout([], 'none', '0', '1000', uuid(), 1), 'empty');
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 0]], 'none', '0', '1000', uuid(), 1));
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 1.5]], 'none', '0', '1000', uuid(), 1));
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => 999999, 'quantity' => 1]], 'none', '0', '1000', uuid(), 1));
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 1]], 'none', '0', '1000', 'not-a-uuid', 1));
});
test('Archived products cannot be sold', function () {
    $id = product(['stock_qty' => '5']);
    ProductService::setActive($id, false);
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $id, 'quantity' => 1]], 'none', '0', '1000', uuid(), 1), 'archived');
    eq(null, ProductService::searchForPos(ProductService::find($id)['sku'])['exact']);
    eq(5, stock($id));
});
test('Repeated request with the same token does not create a duplicate sale', function () use ($p1) {
    $token = uuid();
    $a = SaleService::checkout([['product_id' => $p1, 'quantity' => 1]], 'none', '0', '500', $token, 1);
    $stockAfter = stock($p1);
    $b = SaleService::checkout([['product_id' => $p1, 'quantity' => 1]], 'none', '0', '500', $token, 1);
    eq(false, $a['duplicate']);
    eq(true, $b['duplicate']);
    eq($a['sale']['id'], $b['sale']['id']);
    eq($stockAfter, stock($p1));
    eq(1, (int) DB::value('SELECT COUNT(*) FROM sales WHERE client_token = ?', [$token]));
});
test('Failed transaction rolls back completely', function () use ($p1, $p2) {
    $sales = (int) DB::value('SELECT COUNT(*) FROM sales');
    $items = (int) DB::value('SELECT COUNT(*) FROM sale_items');
    $moves = (int) DB::value('SELECT COUNT(*) FROM stock_movements');
    $s1 = stock($p1);
    // Second line exceeds stock: the whole sale must roll back, including line 1.
    throws(ValidationException::class, fn () => SaleService::checkout([['product_id' => $p1, 'quantity' => 1], ['product_id' => $p2, 'quantity' => 500]], 'none', '0', '999999', uuid(), 1));
    eq($sales, (int) DB::value('SELECT COUNT(*) FROM sales'));
    eq($items, (int) DB::value('SELECT COUNT(*) FROM sale_items'));
    eq($moves, (int) DB::value('SELECT COUNT(*) FROM stock_movements'));
    eq($s1, stock($p1));
});
test('Editing a product later keeps historical sale details', function () use ($p2, &$saleId) {
    $d = ProductService::validate(['name' => 'NGK Iridium (renamed)', 'sku' => 'MS-014', 'barcode' => '4806512384012', 'category' => 'Spark Plugs', 'unit' => 'pc', 'cost_price' => '500', 'selling_price' => '999'], false);
    ProductService::update($p2, $d);
    $sale = SaleService::find($saleId);
    $line = array_values(array_filter($sale['items'], fn ($i) => (int) $i['product_id'] === $p2))[0];
    eq('NGK Iridium Spark Plug', $line['product_name']);
    eq('620.00', $line['unit_price']);
    eq('470.00', $line['unit_cost']);
});

echo "\nVoids\n";
test('Void restores stock atomically, keeps the record and cannot repeat', function () use ($p1, $p2, &$saleId) {
    $s1 = stock($p1);
    $s2 = stock($p2);
    SaleService::void($saleId, 'Wrong item rung up', 1, 1);
    $sale = SaleService::find($saleId);
    eq('voided', $sale['status']);
    eq('Wrong item rung up', $sale['void_reason']);
    eq($s1 + 2, stock($p1));
    eq($s2 + 1, stock($p2));
    eq(2, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE sale_id = ? AND movement_type = 'void'", [$saleId]));
    throws(ValidationException::class, fn () => SaleService::void($saleId, 'again', 1, 1), 'already been voided');
    eq($s1 + 2, stock($p1));
    throws(ValidationException::class, fn () => SaleService::void($saleId, '', 1, 1));
});

echo "\nReports\n";
test('Report totals match database records', function () {
    $today = Clock::todayLocal();
    [$f, $t] = Clock::localRangeToUtc($today, $today);
    $s = ReportService::summary($f, $t);
    $exp = DB::one("SELECT COUNT(*) c, SUM(subtotal) g, SUM(discount_amount) d, SUM(total) n FROM sales WHERE status = 'completed'");
    eq((int) $exp['c'], $s['txn_count']);
    eq(Money::toCents((string) $exp['g']), $s['gross']);
    eq(Money::toCents((string) $exp['d']), $s['discounts']);
    eq(Money::toCents((string) $exp['n']), $s['net']);
    eq($s['gross'] - $s['discounts'], $s['net'], 'net = gross - discounts');
    eq(1, $s['void_count']);
    $cogs = Money::toCents((string) DB::value("SELECT SUM(si.quantity * si.unit_cost) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status = 'completed'"));
    eq($s['net'] - $cogs, $s['profit']);
});
test('Date filters exclude sales outside the range', function () {
    $s = ReportService::summary(...Clock::localRangeToUtc('2020-01-01', '2020-01-31'));
    eq(0, $s['txn_count']);
    eq(0, $s['net']);
    $r = ReportService::build(ReportService::normalize(['type' => 'transactions', 'from' => '2020-01-01', 'to' => '2020-01-02']));
    eq([], $r['rows']);
});
test('Every report type builds for daily, weekly and monthly ranges', function () {
    foreach (array_keys(ReportService::TYPES) as $type) {
        foreach (['day', 'week', 'month'] as $g) {
            $r = ReportService::build(ReportService::normalize(['type' => $type, 'group' => $g, 'preset' => 'month']));
            ok(isset($r['columns'], $r['rows'], $r['summary']), $type);
        }
    }
    $daily = ReportService::build(ReportService::normalize(['type' => 'sales', 'group' => 'day', 'preset' => 'today']));
    eq(1, count($daily['rows']));
});
test('Inventory valuation uses cost prices', function () {
    $r = ReportService::build(ReportService::normalize(['type' => 'inventory']));
    $expected = Money::toCents((string) DB::value('SELECT SUM(GREATEST(stock_qty,0) * cost_price) FROM products WHERE is_active = 1'));
    $row = array_values(array_filter($r['summary'], fn ($x) => $x[0] === 'Inventory value at cost'))[0];
    eq(Money::toDecimal($expected), $row[1]);
});
test('CSV export escapes fields and neutralizes formula injection', function () {
    product(['name' => '=HYPERLINK("http://evil","x")', 'sku' => 'EVIL-1', 'description' => '']);
    product(['name' => 'Bolt, "M8" 1.25', 'sku' => 'BOLT-1']);
    $r = ReportService::build(ReportService::normalize(['type' => 'inventory']));
    $fh = fopen('php://memory', 'w+');
    ReportExporter::csv($r, $fh);
    rewind($fh);
    $csv = stream_get_contents($fh);
    ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM');
    ok(str_contains($csv, "\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\""), 'formula prefixed with apostrophe and quoted');
    ok(str_contains($csv, '"Bolt, ""M8"" 1.25"'), 'comma and quotes escaped');
    ok(str_contains($csv, 'Current inventory & valuation'), 'title');
    // Parse back to be sure it is valid CSV.
    rewind($fh);
    fgets($fh);
    $rows = 0;
    while (($line = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $rows++;
    }
    ok($rows > 5);
    eq("'-5", ReportExporter::csvCell('-5', 'text'));
    eq('-5', ReportExporter::csvCell(-5, 'int'), 'numbers are not prefixed');
    eq("'@SUM(A1)", ReportExporter::csvCell('@SUM(A1)', 'text'));
});
test('PDF export is a structurally valid PDF', function () {
    foreach (['sales', 'inventory', 'transactions', 'movements', 'out_of_stock'] as $type) {
        $pdf = ReportExporter::pdf(ReportService::build(ReportService::normalize(['type' => $type, 'preset' => 'month'])));
        ok(str_starts_with($pdf, '%PDF-1.4'), "$type header");
        ok(str_ends_with(rtrim($pdf), '%%EOF'), "$type trailer");
        preg_match('/startxref\n(\d+)\n/', $pdf, $m);
        eq('xref', substr($pdf, (int) $m[1], 4), "$type xref offset");
        // Every xref offset points at its object.
        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offs);
        foreach ($offs[1] as $i => $off) {
            ok(str_starts_with(substr($pdf, (int) $off), ($i + 1) . ' 0 obj'), "$type object " . ($i + 1));
        }
    }
    // Empty report still produces a PDF.
    $empty = ReportExporter::pdf(ReportService::build(ReportService::normalize(['type' => 'transactions', 'from' => '2020-01-01', 'to' => '2020-01-01'])));
    ok(str_contains(gzuncompress(substr($empty, strpos($empty, "stream\n") + 7, strpos($empty, "\nendstream") - strpos($empty, "stream\n") - 7)), 'No records'));
});
test('Large PDF paginates', function () {
    $rows = [];
    for ($i = 0; $i < 300; $i++) {
        $rows[] = ['a' => "Row $i", 'b' => '12.50'];
    }
    $pdf = ReportExporter::pdf(['type' => 'x', 'title' => 'T', 'range' => 'R', 'columns' => [['a', 'A', 'text'], ['b', 'B', 'money']], 'rows' => $rows, 'summary' => [], 'notes' => []]);
    preg_match('/\/Count (\d+)/', $pdf, $m);
    ok((int) $m[1] >= 5, 'pages: ' . $m[1]);
});

echo "\nConcurrency\n";
test('Concurrent sales cannot oversell the last units', function () use ($db) {
    $id = product(['name' => 'Last unit', 'sku' => 'LAST-1', 'stock_qty' => '3']);
    $workers = 8;
    $script = __DIR__ . '/concurrent_worker.php';
    $procs = [];
    $outs = [];
    for ($i = 0; $i < $workers; $i++) {
        $cmd = [PHP_BINARY, $script, json_encode($db), (string) $id, uuid(), (string) (microtime(true) + 0.6)];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outs[] = $pipes;
    }
    $ok = 0;
    $rejected = 0;
    foreach ($procs as $i => $p) {
        $out = trim(stream_get_contents($outs[$i][1]));
        $err = trim(stream_get_contents($outs[$i][2]));
        proc_close($p);
        if ($out === 'OK') {
            $ok++;
        } elseif ($out === 'REJECTED') {
            $rejected++;
        } else {
            throw new RuntimeException("worker output: $out $err");
        }
    }
    eq(3, $ok, 'exactly the available stock was sold');
    eq($workers - 3, $rejected);
    eq(0, stock($id));
    eq(3, (int) DB::value('SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE product_id = ?', [$id]));
});
test('Concurrent duplicate submissions create one sale', function () use ($db, $p1) {
    $token = uuid();
    $procs = [];
    $outs = [];
    for ($i = 0; $i < 6; $i++) {
        $procs[] = proc_open([PHP_BINARY, __DIR__ . '/concurrent_worker.php', json_encode($db), (string) $p1, $token, (string) (microtime(true) + 0.6)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outs[] = $pipes;
    }
    foreach ($procs as $i => $p) {
        stream_get_contents($outs[$i][1]);
        stream_get_contents($outs[$i][2]);
        proc_close($p);
    }
    eq(1, (int) DB::value('SELECT COUNT(*) FROM sales WHERE client_token = ?', [$token]));
});

echo "\n$passed passed, $failed failed\n";
if ($out = getenv('MOTO_TEST_REPORT')) {
    $md = "| Result | Test | Details |\n|---|---|---|\n";
    foreach ($results as [$r, $n, $d]) {
        $md .= "| $r | " . str_replace('|', '\|', $n) . ' | ' . str_replace('|', '\|', $d) . " |\n";
    }
    file_put_contents($out, $md);
}
exit($failed > 0 ? 1 : 0);
