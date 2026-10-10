<?php
declare(strict_types=1);

/*
 * HTTP tests for the cashier desktop API (api.php) against a real web server and a fresh install.
 *
 *   php tests/api_test.php <base-url> <database> [<https-base-url>]
 *
 * Test users and products are created directly in the (throwaway) database.
 */

[$_, $B, $DBNAME] = $argv + [null, null, null];
$HTTPS = $argv[3] ?? null;
if (!$DBNAME) {
    exit("usage: php tests/api_test.php <base-url> <database> [<https-base-url>]\n");
}
$passed = 0;
$failed = 0;
$allBodies = '';
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  PASS  $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name\n        " . $e->getMessage() . "\n";
    }
}
function ok(bool $c, string $m = 'assertion failed'): void
{
    if (!$c) {
        throw new RuntimeException($m);
    }
}
function eq(mixed $e, mixed $a, string $m = ''): void
{
    if ($e !== $a) {
        throw new RuntimeException(($m ? "$m: " : '') . 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
    }
}
function sql(string $q): string
{
    global $DBNAME;
    $p = proc_open(['mysql', '-N', '-B', $DBNAME], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $q);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    if ($err !== '') {
        throw new RuntimeException("SQL: $err");
    }
    return trim($out);
}
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** @return array{code:int,json:?array,headers:string,body:string} */
function api(string $method, string $route, array $q = [], ?array $body = null, ?string $token = null, array $headers = [], ?string $base = null): array
{
    global $B, $allBodies;
    $ch = curl_init(($base ?? $B) . '/api.php?' . http_build_query(['r' => $route] + $q));
    $h = $headers;
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 30]);
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $r = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
    $r['json'] = json_decode($r['body'], true);
    $allBodies .= $r['body'];
    return $r;
}
function login(string $u, string $p): string
{
    $r = api('POST', 'auth.login', [], ['username' => $u, 'password' => $p, 'device' => 'Test PC']);
    if ($r['code'] !== 200) {
        throw new RuntimeException("login $u failed: {$r['code']} {$r['body']}");
    }
    return $r['json']['token'];
}
function errCode(array $r): string
{
    return (string) ($r['json']['error']['code'] ?? ('HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 80)));
}

// ---------------------------------------------------------------- fixtures
$PW = 'Cashier#Pass42';
$hash = password_hash($PW, PASSWORD_DEFAULT);
$role = static fn (string $slug) => sql("SELECT id FROM roles WHERE slug = '$slug'");
$mkUser = static function (string $u, string $roleSlug, int $active = 1, int $mustChange = 0) use ($hash, $role): int {
    return (int) sql("INSERT INTO users (username, password_hash, full_name, role, role_id, must_change_password, is_active, created_at, updated_at)
        VALUES ('$u', '$hash', '" . ucfirst($u) . "', '$roleSlug', {$role($roleSlug)}, $mustChange, $active, UTC_TIMESTAMP(), UTC_TIMESTAMP()); SELECT LAST_INSERT_ID();");
};
$ids = [
    'cashier' => $mkUser('api_cashier', 'cashier'),
    'cashier2' => $mkUser('api_cashier2', 'cashier'),
    'admin' => $mkUser('api_admin', 'administrator'),
    'stock' => $mkUser('api_stock', 'inventory'),
    'viewer' => $mkUser('api_viewer', 'reports'),
    'custom' => $mkUser('api_custom', 'custom'),
    'inactive' => $mkUser('api_inactive', 'cashier', 0),
    'mustchange' => $mkUser('api_newbie', 'cashier', 1, 1),
];
sql("INSERT INTO user_permissions (user_id, permission, allowed) VALUES ({$ids['custom']}, 'pos.access', 1)");
$mkProduct = static function (string $sku, string $barcode, string $price, int $stock, int $active = 1): int {
    return (int) sql("INSERT INTO products (sku, barcode, name, unit, cost_price, selling_price, stock_qty, is_active, created_at, updated_at)
        VALUES ('$sku', " . ($barcode === '' ? 'NULL' : "'$barcode'") . ", 'Api $sku', 'pc', 100, $price, $stock, $active, UTC_TIMESTAMP(), UTC_TIMESTAMP()); SELECT LAST_INSERT_ID();");
};
$P = [
    'oil' => $mkProduct('API-OIL', '4800000000011', '450.00', 99),
    'plug' => $mkProduct('API-PLUG', '4800000000028', '120.50', 5),
    'last' => $mkProduct('API-LAST', '4800000000035', '300.00', 2),
    'old' => $mkProduct('API-OLD', '4800000000042', '10.00', 50, 0),
];
$stock = static fn (int $id): int => (int) sql("SELECT stock_qty FROM products WHERE id = $id");

echo "\nServer identity and transport\n";
test('ping identifies a MotoSupply server without signing in, and sets no cookies', function () {
    $r = api('GET', 'ping');
    eq(200, $r['code']);
    eq('motosupply-pos', $r['json']['product']);
    eq(1, $r['json']['api']);
    ok(!preg_match('/^set-cookie:/mi', $r['headers']), 'no cookies');
    ok((bool) preg_match('/^cache-control: no-store/mi', $r['headers']));
});
test('Unknown endpoints, wrong methods and non-JSON bodies are refused', function () {
    eq('not_found', errCode(api('GET', 'users')));
    eq('not_found', errCode(api('GET', 'settings.save')));
    eq(405, api('GET', 'sales.checkout')['code']);
    $ch = curl_init($GLOBALS['B'] . '/api.php?r=auth.login');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'username=a&password=b', CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
    eq(415, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
});

echo "\nSign-in: cashier permissions only\n";
test('Cashier signs in and gets a token with user info (no secrets)', function () use ($PW) {
    $r = api('POST', 'auth.login', [], ['username' => 'api_cashier', 'password' => $PW, 'device' => 'Till 1']);
    eq(200, $r['code'], $r['body']);
    ok(str_starts_with($r['json']['token'], 'mst_'));
    eq('api_cashier', $r['json']['user']['username']);
    eq(false, $r['json']['user']['can_discount']);
    ok(!str_contains($r['body'], '$2y$') && !str_contains($r['body'], 'password_hash'));
    eq('1', sql("SELECT COUNT(*) FROM api_tokens WHERE token_hash = SHA2('{$r['json']['token']}', 256)"), 'only the hash is stored');
    eq('0', sql("SELECT COUNT(*) FROM api_tokens WHERE token_hash = '{$r['json']['token']}'"));
});
test('Administrator (has POS permissions) may sign in', function () use ($PW) {
    ok(strlen(login('api_admin', $PW)) > 10);
});
test('Inventory-only, reports-only and POS-access-without-sell accounts are refused', function () use ($PW) {
    foreach (['api_stock', 'api_viewer', 'api_custom'] as $u) {
        $r = api('POST', 'auth.login', [], ['username' => $u, 'password' => $PW]);
        eq(403, $r['code'], $u);
        eq('not_cashier', errCode($r), $u);
        ok(!isset($r['json']['token']), "no token for $u");
    }
});
test('Inactive account and wrong password give the same generic error', function () use ($PW) {
    $a = api('POST', 'auth.login', [], ['username' => 'api_inactive', 'password' => $PW]);
    $b = api('POST', 'auth.login', [], ['username' => 'api_cashier', 'password' => 'wrong']);
    $c = api('POST', 'auth.login', [], ['username' => 'nobody', 'password' => 'x']);
    eq([401, 401, 401], [$a['code'], $b['code'], $c['code']]);
    eq('invalid_credentials', errCode($a));
    eq($a['json']['error']['message'], $c['json']['error']['message'], 'no user enumeration');
});
test('Account that must change its password is sent to the website first', function () use ($PW) {
    eq('password_change_required', errCode(api('POST', 'auth.login', [], ['username' => 'api_newbie', 'password' => $PW])));
});
test('Repeated wrong passwords lock sign-in (shared with the web login throttle)', function () use ($PW) {
    for ($i = 0; $i < 5; $i++) {
        api('POST', 'auth.login', [], ['username' => 'api_cashier2', 'password' => 'bad' . $i]);
    }
    $r = api('POST', 'auth.login', [], ['username' => 'api_cashier2', 'password' => $PW]);
    eq(429, $r['code']);
    eq('locked', errCode($r));
    sql("DELETE FROM login_attempts WHERE username = 'api_cashier2'");
});

echo "\nTokens and sessions\n";
$T = login('api_cashier', $PW);
test('Requests without, or with an invalid, token are refused', function () {
    eq('unauthenticated', errCode(api('GET', 'auth.me')));
    eq('unauthenticated', errCode(api('GET', 'auth.me', [], null, 'mst_' . str_repeat('a', 64))));
    eq('unauthenticated', errCode(api('GET', 'products.search', ['q' => 'oil'], null, 'garbage')));
});
test('Token works in the Authorization header and in X-MotoSupply-Token (CGI hosts)', function () use ($T) {
    eq(200, api('GET', 'auth.me', [], null, $T)['code']);
    eq(200, api('GET', 'auth.me', [], null, null, ['X-MotoSupply-Token: ' . $T])['code']);
});
test('The web application does not accept desktop tokens (admin pages stay closed)', function () use ($T) {
    global $B;
    foreach (['users', 'settings', 'products.create', 'updates', 'reports', 'audit'] as $r) {
        $ch = curl_init("$B/index.php?r=$r");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $T, 'X-MotoSupply-Token: ' . $T]]);
        curl_exec($ch);
        eq(303, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $r);
        ok(str_contains((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'r=login'), "$r redirects to login");
    }
});
test('Idle expiry: an unused token expires', function () use ($PW) {
    $t = login('api_cashier', $PW);
    sql("UPDATE api_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE token_hash = SHA2('$t', 256)");
    eq('session_expired', errCode(api('GET', 'auth.me', [], null, $t)));
});
test('Absolute expiry: a token is never valid more than 12 hours after sign-in', function () use ($PW) {
    $t = login('api_cashier', $PW);
    sql("UPDATE api_tokens SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 13 HOUR) WHERE token_hash = SHA2('$t', 256)");
    eq('session_expired', errCode(api('GET', 'auth.me', [], null, $t)));
});
test('Using a token extends its idle expiry (sliding window)', function () use ($PW) {
    $t = login('api_cashier', $PW);
    sql("UPDATE api_tokens SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE token_hash = SHA2('$t', 256)");
    api('GET', 'auth.me', [], null, $t);
    ok((int) sql("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), expires_at) FROM api_tokens WHERE token_hash = SHA2('$t', 256)") >= 28);
});
test('Sign-out revokes the token immediately', function () use ($PW) {
    $t = login('api_cashier', $PW);
    eq(200, api('POST', 'auth.logout', [], [], $t)['code']);
    eq('unauthenticated', errCode(api('GET', 'auth.me', [], null, $t)));
});
test('Deactivating the account or removing POS permission ends the session at once', function () use ($PW, $ids) {
    $t = login('api_cashier2', $PW);
    sql("INSERT INTO user_permissions (user_id, permission, allowed) VALUES ({$ids['cashier2']}, 'pos.sell', 0)");
    eq('forbidden', errCode(api('GET', 'products.search', ['q' => 'oil'], null, $t)));
    sql("DELETE FROM user_permissions WHERE user_id = {$ids['cashier2']}");
    $t = login('api_cashier2', $PW);
    sql("UPDATE users SET is_active = 0 WHERE id = {$ids['cashier2']}");
    eq('account_disabled', errCode(api('GET', 'auth.me', [], null, $t)));
    sql("UPDATE users SET is_active = 1 WHERE id = {$ids['cashier2']}");
});
test('Per-token request limit returns 429', function () use ($PW) {
    $t = login('api_cashier', $PW);
    sql("UPDATE api_tokens SET window_start = UTC_TIMESTAMP(), window_count = 300 WHERE token_hash = SHA2('$t', 256)");
    $r = api('GET', 'auth.me', [], null, $t);
    eq(429, $r['code']);
    eq('rate_limited', errCode($r));
});

echo "\nProducts\n";
test('Search by name, SKU and barcode; config has shop branding and receipt settings', function () use ($T, $P) {
    foreach (['Api API-OIL', 'API-OIL', '4800000000011'] as $q) {
        $r = api('GET', 'products.search', ['q' => $q], null, $T);
        ok(in_array($P['oil'], array_column($r['json']['results'], 'id'), true), "search $q");
    }
    eq($P['oil'], api('GET', 'products.search', ['q' => '4800000000011'], null, $T)['json']['exact']['id']);
    $c = api('GET', 'config', [], null, $T)['json'];
    ok($c['shop']['name'] !== '' && isset($c['receipt']['auto_print'], $c['receipt']['paper']));
});
test('Barcode lookup: exact match; unknown and archived products are reported as not found', function () use ($T, $P) {
    $r = api('GET', 'products.lookup', ['code' => '4800000000011'], null, $T);
    eq($P['oil'], $r['json']['product']['id']);
    eq(45000, $r['json']['product']['price_cents']);
    eq(99, $r['json']['product']['stock']);
    eq('not_found', errCode(api('GET', 'products.lookup', ['code' => '0000000000000'], null, $T)));
    eq('not_found', errCode(api('GET', 'products.lookup', ['code' => '4800000000042'], null, $T)), 'archived');
});
test('Current stock lookup for the cart', function () use ($T, $P) {
    $r = api('GET', 'products.stock', ['ids' => "{$P['oil']},{$P['plug']}"], null, $T);
    $by = array_column($r['json']['products'], 'stock', 'id');
    eq([$P['oil'] => 99, $P['plug'] => 5], [$P['oil'] => $by[$P['oil']], $P['plug'] => $by[$P['plug']]]);
});

echo "\nCheckout (server is the source of truth)\n";
$checkout = static fn (array $items, string $tendered, ?string $token = null, string $tok = '', array $extra = []): array
    => api('POST', 'sales.checkout', [], array_merge(['items' => $items, 'discount_type' => 'none', 'discount_value' => '0', 'tendered' => $tendered,
        'client_token' => $tok !== '' ? $tok : uuid4()], $extra), $token ?? $GLOBALS['T']);
test('Successful sale: server prices and totals, stock deducted, receipt data returned', function () use ($checkout, $P, $stock) {
    $r = $checkout([['product_id' => $P['oil'], 'quantity' => 2, 'price' => '0.01'], ['product_id' => $P['plug'], 'quantity' => 1]], '2000');
    eq(200, $r['code'], $r['body']);
    $s = $r['json']['sale'];
    eq(false, $r['json']['duplicate']);
    eq('₱1,020.50', $s['total'], 'client price ignored: 2×450 + 120.50');
    eq('₱979.50', $s['change']);
    eq('api_cashier', $s['cashier']);
    eq(2, count($s['items']));
    ok((bool) preg_match('/^MS-\d{6}-[0-9A-F]{6}$/', $s['transaction_no']));
    eq(97, $stock($P['oil']));
    eq(4, $stock($P['plug']));
    $GLOBALS['saleId'] = $s['id'];
});
test('Stock 99 sell 100 / stock 0 sell 1: refused with the latest stock, nothing deducted', function () use ($checkout, $P, $stock) {
    $before = sql('SELECT COUNT(*) FROM sales');
    $r = $checkout([['product_id' => $P['oil'], 'quantity' => 98]], '99999');
    eq(422, $r['code']);
    eq('cart_invalid', errCode($r));
    $s = array_column($r['json']['stock'], 'stock', 'id');
    eq(97, $s[$P['oil']], 'latest available quantity returned');
    $r = $checkout([['product_id' => $P['oil'], 'quantity' => 1], ['product_id' => $P['last'], 'quantity' => 3]], '99999');
    eq(422, $r['code'], 'multi-line cart with one short line');
    eq(97, $stock($P['oil']), 'no partial deduction');
    eq(2, $stock($P['last']));
    eq($before, sql('SELECT COUNT(*) FROM sales'));
});
test('Archived product, zero/negative quantity and short payment are refused', function () use ($checkout, $P) {
    eq(422, $checkout([['product_id' => $P['old'], 'quantity' => 1]], '1000')['code']);
    eq(422, $checkout([['product_id' => $P['oil'], 'quantity' => 0]], '1000')['code']);
    eq(422, $checkout([['product_id' => $P['oil'], 'quantity' => -2]], '1000')['code']);
    $r = $checkout([['product_id' => $P['oil'], 'quantity' => 1]], '100');
    eq(422, $r['code']);
    ok(str_contains($r['json']['error']['message'], 'Insufficient payment'));
});
test('Cashier without the discount permission cannot discount, even with a crafted request', function () use ($checkout, $P, $stock) {
    $r = $checkout([['product_id' => $P['oil'], 'quantity' => 1]], '1000', null, '', ['discount_type' => 'amount', 'discount_value' => '400']);
    eq(403, $r['code']);
    eq(97, $stock($P['oil']));
});
test('Retrying with the same client token returns the original sale (no duplicate)', function () use ($checkout, $P, $stock) {
    $tok = uuid4();
    $a = $checkout([['product_id' => $P['oil'], 'quantity' => 1]], '500', null, $tok);
    $b = $checkout([['product_id' => $P['oil'], 'quantity' => 1]], '500', null, $tok);
    eq([200, 200], [$a['code'], $b['code']]);
    eq(true, $b['json']['duplicate']);
    eq($a['json']['sale']['id'], $b['json']['sale']['id']);
    eq(96, $stock($P['oil']), 'deducted once');
    eq('1', sql("SELECT COUNT(*) FROM sales WHERE client_token = '$tok'"));
    $f = api('GET', 'sales.by-token', ['client_token' => $tok], null, $GLOBALS['T']);
    eq(true, $f['json']['found']);
    eq($a['json']['sale']['id'], $f['json']['sale']['id']);
    eq(false, api('GET', 'sales.by-token', ['client_token' => uuid4()], null, $GLOBALS['T'])['json']['found']);
});
test('Another cashier cannot read a sale by someone else\'s client token', function () use ($PW, $checkout, $P) {
    $tok = uuid4();
    $checkout([['product_id' => $P['oil'], 'quantity' => 1]], '500', null, $tok);
    $t2 = login('api_cashier2', $PW);
    eq(false, api('GET', 'sales.by-token', ['client_token' => $tok], null, $t2)['json']['found']);
});
test('Concurrent checkouts of the last 2 units from 6 tills: exactly 2 succeed', function () use ($P, $stock) {
    global $B, $T;
    $mh = curl_multi_init();
    $hs = [];
    for ($i = 0; $i < 6; $i++) {
        $ch = curl_init("$B/api.php?r=sales.checkout");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $T],
            CURLOPT_POSTFIELDS => json_encode(['items' => [['product_id' => $P['last'], 'quantity' => 1]], 'discount_type' => 'none', 'discount_value' => '0', 'tendered' => '1000', 'client_token' => uuid4()])]);
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    $codes = array_map(static fn ($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $hs);
    eq(2, count(array_filter($codes, static fn ($c) => $c === 200)), implode(',', $codes));
    eq(4, count(array_filter($codes, static fn ($c) => $c === 422)));
    eq(0, $stock($P['last']));
    eq('2', sql("SELECT SUM(quantity) FROM sale_items WHERE product_id = {$P['last']}"));
});

echo "\nReceipts and reprints\n";
test('Own receipt and today\'s own sales are available; another cashier\'s are not', function () use ($PW, $ids) {
    sql("INSERT INTO user_permissions (user_id, permission, allowed) VALUES ({$ids['cashier2']}, 'sales.view', 0)");
    global $T;
    $id = $GLOBALS['saleId'];
    $r = api('GET', 'sales.receipt', ['id' => $id], null, $T);
    eq(200, $r['code']);
    foreach (['transaction_no', 'date', 'cashier', 'items', 'subtotal', 'discount', 'total', 'tendered', 'change'] as $k) {
        ok(array_key_exists($k, $r['json']['sale']), "receipt has $k");
    }
    ok(in_array($id, array_column(api('GET', 'sales.recent', [], null, $T)['json']['sales'], 'id'), true));
    $t2 = login('api_cashier2', $PW);
    eq('not_found', errCode(api('GET', 'sales.receipt', ['id' => $id], null, $t2)), 'cashier with "View sales history" denied');
    $ta = login('api_admin', $PW);
    eq(200, api('GET', 'sales.receipt', ['id' => $id], null, $ta)['code'], 'administrator with sales history');
});
test('Printing a receipt never creates a sale or changes stock', function () use ($P, $stock) {
    global $T;
    $before = [sql('SELECT COUNT(*) FROM sales'), $stock($P['oil'])];
    for ($i = 0; $i < 3; $i++) {
        api('GET', 'sales.receipt', ['id' => $GLOBALS['saleId']], null, $T);
    }
    eq($before, [sql('SELECT COUNT(*) FROM sales'), $stock($P['oil'])]);
});
test('Desktop actions are in the audit log (sign-in, refused sign-in, sale, reprint) without secrets', function () use ($PW) {
    $t = sql("SELECT GROUP_CONCAT(DISTINCT action) FROM audit_log WHERE action LIKE 'api.%' OR details LIKE '%desktop%'");
    foreach (['api.login', 'api.logout', 'sale.completed', 'sale.receipt.print', 'sale.rejected'] as $a) {
        ok(str_contains($t, $a), "audit has $a");
    }
    ok(sql("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%$PW%' OR details LIKE '%mst\\_%'") === '0');
});
test('No API response ever contains password hashes or configuration secrets', function () {
    global $allBodies;
    ok(!str_contains($allBodies, '$2y$') && !str_contains($allBodies, 'password_hash') && !str_contains($allBodies, 'db_pass'));
});

if ($HTTPS) {
    echo "\nHTTPS enforcement\n";
    test('Production mode: plain HTTP is refused, HTTPS works', function () use ($PW, $HTTPS) {
        sql("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('security_mode', 'production', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = 'production'");
        try {
            sleep(1);
            $r = api('POST', 'auth.login', [], ['username' => 'api_cashier', 'password' => $PW]);
            eq(403, $r['code']);
            eq('https_required', errCode($r));
            $s = api('POST', 'auth.login', [], ['username' => 'api_cashier', 'password' => $PW], null, [], $HTTPS);
            eq(200, $s['code'], $s['body']);
        } finally {
            sql("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('security_mode', 'testing', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = 'testing'");
        }
    });
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
