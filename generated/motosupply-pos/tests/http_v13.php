<?php
declare(strict_types=1);

/*
 * HTTP tests for 1.3 against a real web server and a freshly installed site:
 * role-based access on every route, CSRF, the void approval flow, user management rules,
 * branding uploads, theme persistence, the scheduled-task URL, and the updater upload page.
 *
 *   php tests/http_v13.php <base-url> <database> <admin-user> <admin-password> [<https-base-url>]
 *
 * The database name is used to set up test users' passwords directly (skipping the forced
 * first-login change, which tests/e2e.mjs covers in the browser).
 */

[$_, $B, $DBNAME, $AU, $AP] = $argv + [null, null, null, null, null];
$HTTPS = $argv[5] ?? null;
if (!$AP) {
    exit("usage: php tests/http_v13.php <base-url> <database> <admin-user> <admin-password> [<https-base-url>]\n");
}

$passed = 0;
$failed = 0;
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

final class Client
{
    public string $jar;
    public string $csrf = '';

    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    /** @return array{code:int,body:string,location:string,type:string} */
    public function req(string $method, string $route, array $query = [], array $post = [], bool $withCsrf = true): array
    {
        $url = $this->base . '/index.php?' . http_build_query(['r' => $route] + $query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HEADER => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 60]);
        if ($method === 'POST') {
            if ($withCsrf && !isset($post['_csrf'])) {
                $post['_csrf'] = $this->csrf;
            }
            $hasFile = (bool) array_filter($post, static fn ($v) => $v instanceof CURLFile);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $r = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body,
            'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
        if (preg_match('/name="_csrf" value="([^"]+)"/', $body, $m)) {
            $this->csrf = html_entity_decode($m[1]);
        } elseif (preg_match('/data-csrf="([^"]+)"/', $body, $m)) {
            $this->csrf = html_entity_decode($m[1]);
        }
        return $r;
    }

    public function get(string $route, array $q = []): array
    {
        return $this->req('GET', $route, $q);
    }

    public function post(string $route, array $data, array $q = []): array
    {
        return $this->req('POST', $route, $q, $data);
    }

    public function login(string $user, string $pass): void
    {
        $this->get('login');
        $r = $this->post('login', ['username' => $user, 'password' => $pass]);
        if ($r['code'] !== 303 || str_contains($r['location'], 'r=login')) {
            throw new RuntimeException("login failed for $user ({$r['code']} {$r['location']})");
        }
        $this->get('account');
    }

    public function flash(): string
    {
        $r = $this->get('account');
        return preg_match('/class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $r['body'], $m) ? trim(strip_tags($m[1])) : '';
    }
}

$PW = 'Counter#Pass42';
$admin = new Client($B);
$admin->login($AU, $AP);
$roles = [];
foreach (explode("\n", sql('SELECT slug, id FROM roles')) as $line) {
    [$slug, $id] = explode("\t", $line);
    $roles[$slug] = $id;
}

echo "\nUser management over HTTP\n";
test('Administrator creates one user per role', function () use ($admin, $roles, $PW) {
    foreach (['cashier' => 'cashier', 'stock' => 'inventory', 'viewer' => 'reports', 'nobody' => 'custom'] as $u => $role) {
        $r = $admin->post('users.save', ['id' => '0', 'username' => "t_$u", 'full_name' => ucfirst($u), 'role_id' => $roles[$role], 'password' => $PW, 'password2' => $PW]);
        eq(303, $r['code']);
        ok(str_contains($r['location'], 'r=users') && !str_contains($r['location'], 'users.create'), "created t_$u ({$r['location']})");
    }
    sql("UPDATE users SET must_change_password = 0 WHERE username LIKE 't\\_%'");
    eq('4', sql("SELECT COUNT(*) FROM users WHERE username LIKE 't\\_%'"));
});
test('Requests without a valid CSRF token are refused', function () use ($admin, $roles, $PW) {
    $r = $admin->req('POST', 'users.save', [], ['id' => '0', 'username' => 'csrf_user', 'role_id' => $roles['cashier'], 'password' => $PW, 'password2' => $PW], false);
    eq(403, $r['code']);
    $r = $admin->req('POST', 'users.save', [], ['_csrf' => 'forged', 'id' => '0', 'username' => 'csrf_user', 'role_id' => $roles['cashier'], 'password' => $PW, 'password2' => $PW]);
    eq(403, $r['code']);
    eq('0', sql("SELECT COUNT(*) FROM users WHERE username = 'csrf_user'"));
});

$clients = [];
foreach (['cashier', 'stock', 'viewer', 'nobody'] as $u) {
    $clients[$u] = new Client($B);
    $clients[$u]->login("t_$u", $PW);
}
$clients['admin'] = $admin;
$anon = new Client($B);

echo "\nRole-based access (server-side, deny by default)\n";
// route => roles that may open it (GET). Everyone else must get 403 (logged in) or a login redirect.
$matrix = [
    'dashboard' => ['admin', 'cashier', 'stock', 'viewer'],
    'pos' => ['admin', 'cashier'],
    'api.products.search' => ['admin', 'cashier'],
    'products' => ['admin', 'cashier', 'stock'],
    'products.create' => ['admin', 'stock'],
    'products.import' => ['admin', 'stock'],
    'products.import.template' => ['admin', 'stock'],
    'inventory.integrity' => ['admin', 'stock'],
    'sales' => ['admin', 'cashier', 'viewer'],
    'reports' => ['admin', 'stock', 'viewer'],
    'reports.export' => ['admin', 'viewer'],
    'users' => ['admin'],
    'users.create' => ['admin'],
    'settings' => ['admin'],
    'settings.receipt' => ['admin'],
    'settings.email' => ['admin'],
    'settings.appearance' => ['admin'],
    'settings.system' => ['admin'],
    'audit' => ['admin'],
    'updates' => ['admin'],
    'account' => ['admin', 'cashier', 'stock', 'viewer', 'nobody'],
];
foreach ($matrix as $route => $allowed) {
    test("GET $route: allowed for " . implode(', ', $allowed), function () use ($route, $allowed, $clients, $anon) {
        $q = $route === 'reports.export' ? ['type' => 'sales', 'format' => 'csv', 'preset' => 'today'] : [];
        foreach ($clients as $who => $c) {
            $r = $c->get($route, $q);
            if (in_array($who, $allowed, true)) {
                ok(in_array($r['code'], [200, 303], true) && !($r['code'] === 303 && str_contains($r['location'], 'r=login')), "$who should open $route (got {$r['code']})");
            } else {
                eq(403, $r['code'], "$who must be refused $route");
            }
        }
        $r = $anon->get($route, $q);
        ok($r['code'] === 401 || ($r['code'] === 303 && str_contains($r['location'], 'r=login')), "anonymous must be sent to login for $route (got {$r['code']})");
    });
}
test('Refused requests are recorded in the audit log', function () {
    ok((int) sql("SELECT COUNT(*) FROM audit_log WHERE action = 'access.denied' AND username = 't_cashier'") > 0);
});
test('Forged POSTs to protected actions are refused for roles without permission', function () use ($clients, $roles, $PW) {
    $r = $clients['cashier']->post('users.save', ['id' => '0', 'username' => 'evil1', 'role_id' => $roles['administrator'], 'password' => $PW, 'password2' => $PW]);
    eq(403, $r['code']);
    $r = $clients['viewer']->post('settings.save', ['shop_name' => 'Hacked']);
    eq(403, $r['code']);
    $r = $clients['stock']->post('settings.appearance', ['theme_primary' => '#000000', 'theme_sidebar' => '#000000']);
    eq(403, $r['code']);
    $r = $clients['cashier']->post('updates.upload', []);
    eq(403, $r['code']);
    eq('0', sql("SELECT COUNT(*) FROM users WHERE username = 'evil1'"));
    ok(sql("SELECT setting_value FROM settings WHERE setting_key = 'shop_name'") !== 'Hacked');
});
test('Cashier cannot apply a discount (server rejects it even if the request is crafted)', function () use ($clients) {
    $pid = sql("INSERT INTO products (sku, name, unit, cost_price, selling_price, stock_qty, is_active, created_at, updated_at) VALUES ('HTTP-1', 'Http test oil', 'pc', 100, 200, 10, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()); SELECT LAST_INSERT_ID();");
    $c = $clients['cashier'];
    $c->get('pos');
    $payload = json_encode(['items' => [['product_id' => (int) $pid, 'quantity' => 1]], 'discount_type' => 'amount', 'discount_value' => '50', 'tendered' => '1000', 'client_token' => uuid4()]);
    $ch = curl_init($c->base . '/index.php?r=api.sales.checkout');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $c->jar, CURLOPT_COOKIEJAR => $c->jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf]]);
    $body = (string) curl_exec($ch);
    eq(403, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body);
    eq('10', sql("SELECT stock_qty FROM products WHERE id = $pid"));
    $GLOBALS['httpPid'] = (int) $pid;
});

echo "\nVoid approval over HTTP\n";
test('Void PIN is set in My Account (needs the current password; must differ from it)', function () use ($admin, $AP) {
    $admin->get('account');
    $admin->post('account.pin', ['current_password' => 'wrong', 'pin' => '482915', 'pin2' => '482915']);
    eq('NULL', sql("SELECT IFNULL(void_pin_hash, 'NULL') FROM users WHERE id = 1"));
    $admin->post('account.pin', ['current_password' => $AP, 'pin' => '123456', 'pin2' => '123456']);
    eq('NULL', sql("SELECT IFNULL(void_pin_hash, 'NULL') FROM users WHERE id = 1"), 'sequence rejected');
    $admin->post('account.pin', ['current_password' => $AP, 'pin' => '482915', 'pin2' => '482915']);
    ok(str_starts_with(sql('SELECT void_pin_hash FROM users WHERE id = 1'), '$'), 'hash stored');
    $r = $admin->get('account');
    ok(!str_contains($r['body'], '482915'));
});
test('Void: no permission → 403; wrong PIN → refused; correct approver → voided once', function () use ($clients, $admin, $AU) {
    $pid = $GLOBALS['httpPid'];
    $c = $clients['cashier'];
    $c->get('pos');
    $payload = json_encode(['items' => [['product_id' => $pid, 'quantity' => 2]], 'discount_type' => 'none', 'discount_value' => '0', 'tendered' => '1000', 'client_token' => uuid4()]);
    $ch = curl_init($c->base . '/index.php?r=api.sales.checkout');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $c->jar, CURLOPT_COOKIEJAR => $c->jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf]]);
    $res = json_decode((string) curl_exec($ch), true);
    ok(($res['ok'] ?? false) === true, json_encode($res));
    $saleId = (int) $res['sale']['id'];
    eq('8', sql("SELECT stock_qty FROM products WHERE id = $pid"));

    $c->get('sales.view', ['id' => $saleId]);
    eq(403, $c->post('sales.void', ['id' => (string) $saleId, 'reason' => 'x', 'approver' => $AU, 'pin' => '482915'])['code'], 'cashier lacks sales.void');

    $admin->get('sales.view', ['id' => $saleId]);
    $admin->post('sales.void', ['id' => (string) $saleId, 'reason' => 'Customer changed mind', 'approver' => $AU, 'pin' => '000000']);
    eq('completed', sql("SELECT status FROM sales WHERE id = $saleId"));
    $admin->post('sales.void', ['id' => (string) $saleId, 'reason' => '', 'approver' => $AU, 'pin' => '482915']);
    eq('completed', sql("SELECT status FROM sales WHERE id = $saleId"), 'reason required');
    $admin->post('sales.void', ['id' => (string) $saleId, 'reason' => 'Customer changed mind', 'approver' => $AU, 'pin' => '482915']);
    eq("voided\t1\t1\tCustomer changed mind", sql("SELECT status, voided_by, void_approved_by, void_reason FROM sales WHERE id = $saleId"));
    eq('10', sql("SELECT stock_qty FROM products WHERE id = $pid"));
    $admin->post('sales.void', ['id' => (string) $saleId, 'reason' => 'again', 'approver' => $AU, 'pin' => '482915']);
    eq('10', sql("SELECT stock_qty FROM products WHERE id = $pid"), 'second void has no effect');
    $r = $admin->get('sales.view', ['id' => $saleId]);
    ok(str_contains($r['body'], 'Customer changed mind') && str_contains($r['body'], $AU));
    eq('1', sql("SELECT COUNT(*) FROM audit_log WHERE action = 'sale.void' AND status = 'success' AND entity_id = '$saleId'"));
    ok((int) sql("SELECT COUNT(*) FROM audit_log WHERE action = 'sale.void' AND status = 'failure' AND entity_id = '$saleId'") >= 2);
    ok(sql("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%482915%' OR details LIKE '%000000%'") === '0', 'PINs never in the audit log');
});

echo "\nUser rules over HTTP\n";
test('Admin cannot deactivate itself; deactivated user is logged out immediately', function () use ($admin, $clients) {
    $admin->get('users');
    $admin->post('users.status', ['id' => '1', 'active' => '0']);
    eq('1', sql('SELECT is_active FROM users WHERE id = 1'));
    $nid = sql("SELECT id FROM users WHERE username = 't_nobody'");
    $admin->post('users.status', ['id' => $nid, 'active' => '0']);
    eq('0', sql("SELECT is_active FROM users WHERE id = $nid"));
    $r = $clients['nobody']->get('account');
    ok($r['code'] === 303 && str_contains($r['location'], 'r=login'), 'session ended');
});
test('Password reset shows a temporary password once and forces a change', function () use ($admin) {
    $sid = sql("SELECT id FROM users WHERE username = 't_stock'");
    $admin->get('users.edit', ['id' => $sid]);
    $admin->post('users.reset-password', ['id' => $sid]);
    $page = $admin->get('users')['body'];
    ok(preg_match('/<code[^>]*>([A-Za-z0-9#]{12,})<\/code>/', $page, $m) === 1, 'temporary password shown');
    eq('1', sql("SELECT must_change_password FROM users WHERE id = $sid"));
    $again = $admin->get('users')['body'];
    ok(!str_contains($again, $m[1]), 'shown only once');
    $t = new Client($admin->base);
    $t->login('t_stock', $m[1]);
    $r = $t->get('dashboard');
    ok($r['code'] === 303 && str_contains($r['location'], 'password.change'), 'must change password first');
});

echo "\nBranding and theme\n";
$png = static function (int $w, int $h): string {
    $f = tempnam(sys_get_temp_dir(), 'png') . '.png';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 221, 74, 43));
    imagepng($im, $f);
    return $f;
};
test('Logo upload: valid PNG is stored under a random name and shown on login and receipts', function () use ($admin, $png, $B) {
    $admin->get('settings.appearance');
    $r = $admin->post('settings.branding', ['kind' => 'logo', 'logo' => new CURLFile($png(240, 80), 'image/png', 'my shop logo.png')]);
    eq(303, $r['code']);
    $path = sql("SELECT setting_value FROM settings WHERE setting_key = 'logo_path'");
    ok((bool) preg_match('#^uploads/branding/logo-[a-f0-9]{24}\.png$#', $path), $path);
    $login = (new Client($B))->get('login')['body'];
    ok(str_contains($login, $path), 'logo on login page');
    $img = curl_init("$B/$path");
    curl_setopt($img, CURLOPT_RETURNTRANSFER, true);
    curl_exec($img);
    eq(200, curl_getinfo($img, CURLINFO_RESPONSE_CODE));
    eq('image/png', curl_getinfo($img, CURLINFO_CONTENT_TYPE));
});
test('Branding rejects PHP disguised as an image, SVG, oversize files; uploads dir never runs PHP', function () use ($admin, $B) {
    $before = sql("SELECT setting_value FROM settings WHERE setting_key = 'logo_path'");
    $evil = tempnam(sys_get_temp_dir(), 'evil');
    file_put_contents($evil, "\x89PNG\r\n\x1a\n<?php echo 'PWNED'; ?>");
    $admin->post('settings.branding', ['kind' => 'logo', 'logo' => new CURLFile($evil, 'image/png', 'logo.php')]);
    $svg = tempnam(sys_get_temp_dir(), 'svg');
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $admin->post('settings.branding', ['kind' => 'logo', 'logo' => new CURLFile($svg, 'image/svg+xml', 'logo.svg')]);
    $big = tempnam(sys_get_temp_dir(), 'big');
    file_put_contents($big, str_repeat('A', 1100000));
    $admin->post('settings.branding', ['kind' => 'logo', 'logo' => new CURLFile($big, 'image/png', 'big.png')]);
    eq($before, sql("SELECT setting_value FROM settings WHERE setting_key = 'logo_path'"), 'logo unchanged');
    $files = glob('/var/www/mototest/uploads/branding/*') ?: [];
    foreach ($files as $f) {
        ok((bool) preg_match('/\.(png|jpe?g|webp|ico|html)$/', $f), "unexpected file $f");
    }
    // Even if a PHP file got into uploads/, the web server must not execute it.
    file_put_contents('/var/www/mototest/uploads/branding/probe.php', '<?php echo "EXEC" . "UTED";');
    $ch = curl_init("$B/uploads/branding/probe.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = (string) curl_exec($ch);
    ok(!str_contains($body, 'EXECUTED'), 'PHP in uploads must not run');
    eq(403, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    unlink('/var/www/mototest/uploads/branding/probe.php');
});
test('Favicon upload (PNG) replaces the default favicon; removal restores it', function () use ($admin, $png, $B) {
    $admin->get('settings.appearance');
    $admin->post('settings.branding', ['kind' => 'favicon', 'favicon' => new CURLFile($png(64, 64), 'image/png', 'fav.png')]);
    $path = sql("SELECT setting_value FROM settings WHERE setting_key = 'favicon_path'");
    ok(str_starts_with($path, 'uploads/branding/favicon-'), $path);
    ok(str_contains((new Client($B))->get('login')['body'], $path));
    $admin->get('settings.appearance');
    $admin->post('settings.branding', ['kind' => 'favicon', 'remove' => '1']);
    eq('', sql("SELECT setting_value FROM settings WHERE setting_key = 'favicon_path'"));
    ok(!is_file('/var/www/mototest/' . $path), 'old file deleted');
});
test('Theme colours persist, are served as CSS variables, and unsafe values are refused', function () use ($admin, $B) {
    $admin->get('settings.appearance');
    $admin->post('settings.appearance', ['theme_primary' => '#1d4ed8', 'theme_sidebar' => '#0f172a']);
    $css = (new Client($B))->get('theme.css');
    ok(str_contains($css['body'], '#1d4ed8') && str_contains($css['type'], 'text/css'), $css['type']);
    $admin->get('settings.appearance');
    $admin->post('settings.appearance', ['theme_primary' => '#fff;}*{display:none', 'theme_sidebar' => '#0f172a']);
    eq('#1d4ed8', sql("SELECT setting_value FROM settings WHERE setting_key = 'theme_primary'"));
    $admin->post('settings.appearance', ['theme_primary' => '#ffff00', 'theme_sidebar' => '#0f172a']);
    eq('#1d4ed8', sql("SELECT setting_value FROM settings WHERE setting_key = 'theme_primary'"), 'low-contrast colour refused');
    $admin->get('settings.appearance');
    $admin->post('settings.appearance', ['reset' => '1']);
    eq('#dd4a2b', sql("SELECT setting_value FROM settings WHERE setting_key = 'theme_primary'"));
});

echo "\nScheduled-task URL and updates page\n";
test('Cron URL: refused over HTTP and with a wrong key; key shown once, stored only as a hash', function () use ($admin, $B, $HTTPS) {
    $admin->get('settings.email');
    $admin->post('settings.email.cron-key', []);
    $page = $admin->get('settings.email')['body'];
    ok(preg_match('/key=([a-f0-9]{48})/', $page, $m) === 1, 'key shown');
    $key = $m[1];
    eq(hash('sha256', $key), sql("SELECT setting_value FROM settings WHERE setting_key = 'email_cron_key_hash'"));
    ok(!str_contains($admin->get('settings.email')['body'], $key), 'shown only once');
    $c = new Client($B);
    eq(403, $c->get('cron.daily-report', ['key' => $key])['code'], 'HTTP refused');
    if ($HTTPS) {
        $s = new Client($HTTPS);
        eq(403, $s->get('cron.daily-report', ['key' => str_repeat('0', 48)])['code']);
        $r = $s->get('cron.daily-report', ['key' => $key]);
        eq(200, $r['code']);
        eq('disabled', trim($r['body']));
    }
    ok(!str_contains(sql('SELECT GROUP_CONCAT(details) FROM audit_log'), $key), 'key not in audit log');
});
test('Updates page: refuses non-update ZIPs and keeps data', function () use ($admin) {
    $admin->get('updates');
    $zipPath = tempnam(sys_get_temp_dir(), 'z') . '.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE);
    $z->addFromString('index.php', '<?php echo 1;');
    $z->close();
    $r = $admin->post('updates.upload', ['package' => new CURLFile($zipPath, 'application/zip', 'MotoSupply-POS-Installer.zip')]);
    $page = $admin->get('updates')['body'];
    ok(str_contains($page, 'manifest or signature missing') || str_contains($page, 'not a MotoSupply update package'), 'clear rejection shown');
    eq('0', sql("SELECT COUNT(*) FROM update_history"));
});

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
