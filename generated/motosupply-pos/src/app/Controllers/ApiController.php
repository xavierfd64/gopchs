<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Services\ApiTokens;
use App\Services\ProductService;
use App\Services\SaleService;

/**
 * JSON API for the MotoSupply cashier desktop app (entry point: api.php).
 *
 * Only cashier operations are available: sign in/out, product search and barcode lookup,
 * current stock, checkout, and receipts. Every request is authenticated with a short-lived
 * bearer token and every operation re-checks the user's permissions on the server. Prices,
 * stock and totals are always taken from the database (SaleService), never from the client.
 *
 * Responses: {"ok": true, ...} or {"ok": false, "error": {"code": "...", "message": "..."}}.
 */
final class ApiController
{
    /** Permissions a user needs to use the cashier app at all. */
    public const REQUIRED = ['pos.access', 'pos.sell'];

    private ?array $token = null;

    // ---------------------------------------------------------------- helpers

    public static function fail(string $code, string $message, int $status, array $extra = []): never
    {
        Http::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]] + $extra, $status);
    }

    /** Authenticate the bearer token and the user behind it, with the cashier permissions. */
    public function authenticate(): void
    {
        // Some shared hosts (PHP as CGI/FastCGI) drop the Authorization header; the app also sends
        // the token in X-MotoSupply-Token, which always reaches PHP.
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/^Bearer\s+(\S+)$/', $header, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_MOTOSUPPLY_TOKEN'] ?? '');
        if ($token === '') {
            self::fail('unauthenticated', 'Please sign in.', 401);
        }
        $row = ApiTokens::check($token);
        if ($row === 'expired') {
            self::fail('session_expired', 'Your session has expired. Please sign in again.', 401);
        }
        if ($row === 'rate') {
            header('Retry-After: 60');
            self::fail('rate_limited', 'Too many requests. Wait a moment and try again.', 429);
        }
        if (!is_array($row)) {
            self::fail('unauthenticated', 'Please sign in.', 401);
        }
        if (!Auth::actAs((int) $row['user_id'])) {
            ApiTokens::revoke((int) $row['id']);
            self::fail('account_disabled', 'This account is no longer active. Please contact your administrator.', 401);
        }
        if (!$this->hasCashierAccess()) {
            ApiTokens::revoke((int) $row['id']);
            self::fail('forbidden', 'This account is no longer allowed to use the cashier app.', 403);
        }
        $this->token = $row;
    }

    private function hasCashierAccess(): bool
    {
        foreach (self::REQUIRED as $perm) {
            if (!Auth::can($perm)) {
                return false;
            }
        }
        return true;
    }

    private function userInfo(): array
    {
        $u = Auth::user();
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'name' => $u['full_name'] !== '' ? $u['full_name'] : $u['username'],
            'role' => $u['role_name'],
            'can_discount' => Auth::can('pos.discount'),
            'can_view_sales' => Auth::can('sales.view'),
            // May change which server the desktop app uses (Settings → Store permission).
            'can_configure' => Auth::can('settings.manage'),
        ];
    }

    private static function productJson(array $p): array
    {
        $price = Money::toCents((string) $p['selling_price']);
        return [
            'id' => (int) $p['id'],
            'sku' => $p['sku'],
            'barcode' => $p['barcode'],
            'name' => $p['name'],
            'unit' => $p['unit'],
            'category' => $p['category_name'],
            'price_cents' => $price,
            'price' => Money::format($price),
            'stock' => max(0, (int) $p['stock_qty']),
            'low' => (int) $p['stock_qty'] <= (int) $p['effective_threshold'],
        ];
    }

    // ---------------------------------------------------------------- public

    /** Identifies a MotoSupply server (used by the app's setup screen). No secrets. */
    public function ping(): void
    {
        Http::json(['ok' => true, 'product' => 'motosupply-pos', 'api' => 1, 'version' => MOTO_VERSION,
            'https' => Http::isHttps(), 'time' => gmdate('c')]);
    }

    /** Shop name, logo and colour for the sign-in screen (the same public branding as the website's login page). */
    public function branding(): void
    {
        Http::json(['ok' => true, 'name' => Settings::get('shop_name'), 'logo' => self::logoDataUri(),
            'theme_primary' => Settings::get('theme_primary', '#dd4a2b'), 'theme_sidebar' => Settings::get('theme_sidebar', '#1f2024')]);
    }

    private static function logoDataUri(): ?string
    {
        $path = Settings::get('logo_path');
        if ($path === '' || !is_file(MOTO_ROOT . '/' . $path) || filesize(MOTO_ROOT . '/' . $path) > 1048576) {
            return null;
        }
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        return $mime === null ? null : 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents(MOTO_ROOT . '/' . $path));
    }

    public function login(): void
    {
        $body = Http::jsonBody();
        $username = is_string($body['username'] ?? null) ? $body['username'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $device = is_string($body['device'] ?? null) ? preg_replace('/[^\w .\-()]/u', '', $body['device']) : '';
        if ($username === '' || $password === '') {
            self::fail('invalid_request', 'Enter your username and password.', 422);
        }
        [$status, $user] = Auth::verifyCredentials($username, $password, Http::clientIp());
        if ($status === 'locked') {
            self::fail('locked', 'Too many failed sign-in attempts. Please wait 15 minutes and try again.', 429);
        }
        if ($status !== 'ok') {
            Audit::log('api.login', 'user', mb_substr($username, 0, 50), ['device' => $device], 'failure', ['id' => null, 'username' => mb_substr($username, 0, 50)]);
            self::fail('invalid_credentials', 'Invalid username or password.', 401);
        }
        Auth::actAs((int) $user['id']);
        if (!$this->hasCashierAccess()) {
            Audit::log('api.login', 'user', (int) $user['id'], ['device' => $device, 'reason' => 'no cashier permission'], 'failure');
            self::fail('not_cashier', 'This account is not allowed to use the cashier app. Ask an administrator for POS access.', 403);
        }
        if ((int) $user['must_change_password'] === 1) {
            self::fail('password_change_required', 'You must choose a new password first. Sign in once on the MotoSupply website to change it, then try again.', 403);
        }
        [$token, $expires] = ApiTokens::issue((int) $user['id'], $device, Http::clientIp());
        Audit::log('api.login', 'user', (int) $user['id'], ['device' => $device]);
        Http::json(['ok' => true, 'token' => $token, 'expires_in' => ApiTokens::idleSeconds(),
            'max_session_seconds' => ApiTokens::MAX_LIFETIME_SECONDS, 'user' => $this->userInfo()]);
    }

    // ---------------------------------------------------------------- authenticated

    public function logout(): void
    {
        ApiTokens::revoke((int) $this->token['id']);
        Audit::log('api.logout', 'user', Auth::id(), ['device' => $this->token['device_name']]);
        Http::json(['ok' => true]);
    }

    public function me(): void
    {
        Http::json(['ok' => true, 'user' => $this->userInfo(), 'expires_in' => ApiTokens::idleSeconds()]);
    }

    /** Shop branding and receipt settings for the cashier screen and printed receipts. */
    public function config(): void
    {
        $logo = self::logoDataUri();
        $categories = DB::all(
            'SELECT c.id, c.name FROM categories c
              WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1) ORDER BY c.name'
        );
        Http::json(['ok' => true, 'shop' => [
            'name' => Settings::get('shop_name'),
            'address' => Settings::get('shop_address'),
            'phone' => Settings::get('shop_phone'),
            'email' => Settings::get('shop_email'),
            'logo' => $logo,
            'currency_symbol' => Settings::get('currency_symbol', '₱'),
            'timezone' => Settings::get('timezone'),
            'theme_primary' => Settings::get('theme_primary', '#dd4a2b'),
        ], 'receipt' => [
            'auto_print' => Settings::get('receipt_auto_print') === '1',
            'paper' => Settings::get('receipt_paper', '80mm'),
            'show_logo' => Settings::get('receipt_show_logo') === '1',
            'footer' => Settings::get('receipt_footer'),
        ], 'pos' => [
            'auto_add_barcode' => Settings::get('pos_auto_add_barcode') === '1',
            'confirm_clear' => Settings::get('pos_confirm_clear') === '1',
        ], 'categories' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $categories)]);
    }

    /** Search by name, SKU or barcode (or browse a category when q is empty). */
    public function search(): void
    {
        $q = mb_substr(Http::query('q'), 0, 100);
        $category = ctype_digit(Http::query('category')) ? (int) Http::query('category') : 0;
        $res = trim($q) === ''
            ? ['exact' => null, 'results' => ProductService::browseForPos($category)]
            : ProductService::searchForPos($q, $category);
        Http::json(['ok' => true, 'exact' => $res['exact'] ? self::productJson($res['exact']) : null,
            'results' => array_map([self::class, 'productJson'], $res['results'])]);
    }

    /** Exact barcode or SKU lookup for scanners. Only active products are returned. */
    public function lookup(): void
    {
        $code = trim(mb_substr(Http::query('code'), 0, 64));
        if ($code === '') {
            self::fail('invalid_request', 'Scan or type a barcode.', 422);
        }
        $res = ProductService::searchForPos($code, 0, 1);
        if ($res['exact'] === null) {
            self::fail('not_found', 'No active product has the barcode or SKU "' . $code . '".', 404);
        }
        Http::json(['ok' => true, 'product' => self::productJson($res['exact'])]);
    }

    /** Current stock and price of the products in a cart (ids=1,2,3). Read-only. */
    public function stock(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', Http::query('ids'))), static fn ($i) => $i > 0)));
        if ($ids === [] || count($ids) > 200) {
            self::fail('invalid_request', 'Give 1 to 200 product ids.', 422);
        }
        Http::json(['ok' => true, 'products' => self::stockOf($ids)]);
    }

    /** @param list<int> $ids */
    private static function stockOf(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::all("SELECT id, name, selling_price, stock_qty, is_active FROM products WHERE id IN ($ph)", $ids);
        return array_map(static fn ($p) => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'stock' => max(0, (int) $p['stock_qty']),
            'active' => (int) $p['is_active'] === 1, 'price_cents' => Money::toCents((string) $p['selling_price']),
        ], $rows);
    }

    /**
     * Complete a sale through the same SaleService as the web POS (one transaction, row locks,
     * server prices, stock check, idempotent client_token). Retrying with the same client_token
     * after a timeout returns the original sale instead of creating a second one.
     */
    public function checkout(): void
    {
        $body = Http::jsonBody();
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $discountType = is_string($body['discount_type'] ?? null) ? $body['discount_type'] : 'none';
        if ($discountType !== 'none' && !Auth::can('pos.discount')) {
            Audit::log('sale.discount.denied', 'sale', '', ['discount_type' => $discountType, 'via' => 'desktop'], 'failure');
            self::fail('forbidden', 'You do not have permission to apply discounts.', 403);
        }
        try {
            $result = SaleService::checkout(
                $items,
                $discountType,
                is_string($body['discount_value'] ?? null) ? $body['discount_value'] : '0',
                is_string($body['tendered'] ?? null) ? $body['tendered'] : '',
                is_string($body['client_token'] ?? null) ? $body['client_token'] : '',
                Auth::id()
            );
        } catch (ValidationException $e) {
            Audit::log('sale.rejected', 'sale', '', ['error' => $e->getMessage(), 'via' => 'desktop'], 'failure');
            $ids = array_values(array_filter(array_map(static fn ($i) => (int) ($i['product_id'] ?? 0), $items), static fn ($i) => $i > 0));
            self::fail(isset($e->errors['cart']) ? 'cart_invalid' : 'invalid_request', $e->getMessage(), 422,
                ['errors' => $e->errors, 'stock' => $ids ? self::stockOf(array_slice(array_values(array_unique($ids)), 0, 200)) : []]);
        } catch (\Throwable $e) {
            Logger::error('Desktop checkout failed', $e);
            self::fail('server_error', 'The sale could not be completed. Nothing was charged or deducted. Please try again.', 500);
        }
        $sale = $result['sale'];
        if (!$result['duplicate']) {
            Audit::log('sale.completed', 'sale', (int) $sale['id'], ['transaction_no' => $sale['transaction_no'], 'total' => $sale['total'], 'via' => 'desktop']);
        }
        Http::json(['ok' => true, 'duplicate' => $result['duplicate'], 'sale' => self::receiptJson($sale)]);
    }

    /** Status of a checkout by its client_token (after a crash or lost response). Own sales only. */
    public function byToken(): void
    {
        $token = Http::query('client_token');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $token)) {
            self::fail('invalid_request', 'Invalid transaction token.', 422);
        }
        $id = DB::value('SELECT id FROM sales WHERE client_token = ? AND user_id = ?', [$token, Auth::id()]);
        if ($id === null) {
            Http::json(['ok' => true, 'found' => false]);
        }
        Http::json(['ok' => true, 'found' => true, 'sale' => self::receiptJson(SaleService::find((int) $id))]);
    }

    /** Receipt data for printing or reprinting: own sales, or any sale with "View sales history". */
    public function receipt(): void
    {
        $id = ctype_digit(Http::query('id')) ? (int) Http::query('id') : 0;
        $sale = $id > 0 ? SaleService::find($id) : null;
        if ($sale === null || (!Auth::can('sales.view') && (int) $sale['user_id'] !== Auth::id())) {
            self::fail('not_found', 'Sale not found.', 404);
        }
        Audit::log('sale.receipt.print', 'sale', $id, ['via' => 'desktop']);
        Http::json(['ok' => true, 'sale' => self::receiptJson($sale)]);
    }

    /** Today's sales by this cashier (for reprinting). */
    public function recent(): void
    {
        [$from, $to] = Clock::localRangeToUtc(Clock::todayLocal(), Clock::todayLocal());
        $rows = DB::all(
            'SELECT id, transaction_no, created_at, total, status, item_count FROM sales
              WHERE user_id = ? AND created_at >= ? AND created_at < ? ORDER BY id DESC LIMIT 50',
            [Auth::id(), $from, $to]
        );
        Http::json(['ok' => true, 'sales' => array_map(static fn ($s) => [
            'id' => (int) $s['id'], 'transaction_no' => $s['transaction_no'], 'time' => Clock::toLocal($s['created_at'], 'g:i A'),
            'total' => Money::format(Money::toCents((string) $s['total'])), 'items' => (int) $s['item_count'], 'status' => $s['status'],
        ], $rows)]);
    }

    private static function receiptJson(array $sale): array
    {
        $m = static fn ($v) => Money::format(Money::toCents((string) $v));
        return [
            'id' => (int) $sale['id'],
            'transaction_no' => $sale['transaction_no'],
            'status' => $sale['status'],
            'date' => Clock::toLocal($sale['created_at'], 'M j, Y g:i A'),
            'cashier' => $sale['cashier'],
            'items' => array_map(static fn ($i) => [
                'name' => $i['product_name'], 'sku' => $i['sku'], 'quantity' => (int) $i['quantity'],
                'unit_price' => $m($i['unit_price']), 'line_total' => $m($i['line_total']),
            ], $sale['items']),
            'item_count' => (int) $sale['item_count'],
            'subtotal' => $m($sale['subtotal']),
            'discount' => $m($sale['discount_amount']),
            'has_discount' => Money::toCents((string) $sale['discount_amount']) > 0,
            'total' => $m($sale['total']),
            'tendered' => $m($sale['amount_tendered']),
            'change' => $m($sale['change_due']),
        ];
    }
}
