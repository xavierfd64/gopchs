<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Services\ProductService;
use App\Services\SaleService;

final class PosController extends Controller
{
    public function index(): void
    {
        $categories = DB::all(
            'SELECT c.id, c.name FROM categories c
              WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1)
              ORDER BY c.name'
        );
        $this->view('pages/pos', [
            'title' => 'Point of Sale',
            'nav' => 'pos',
            'subtitle' => \App\Core\Settings::get('shop_name') . ' · Cashier: ' . (Auth::user()['full_name'] ?: Auth::user()['username']),
            'heading' => 'Point of Sale',
            'categories' => $categories,
            'autoAdd' => Settings::get('pos_auto_add_barcode') === '1',
            'confirmClear' => Settings::get('pos_confirm_clear') === '1',
            'scripts' => ['js/pos.js'],
        ]);
    }

    /** JSON product search used by the POS search box and barcode scanners. */
    public function search(): void
    {
        $q = Http::query('q');
        $category = ctype_digit(Http::query('category')) ? (int) Http::query('category') : 0;
        $res = $q === ''
            ? ['exact' => null, 'results' => ProductService::browseForPos($category)]
            : ProductService::searchForPos($q, $category);
        $map = static fn (array $p): array => [
            'id' => (int) $p['id'],
            'sku' => $p['sku'],
            'barcode' => $p['barcode'],
            'name' => $p['name'],
            'unit' => $p['unit'],
            'category' => $p['category_name'],
            'price' => Money::toDecimal(Money::toCents((string) $p['selling_price'])),
            'price_display' => Money::format(Money::toCents((string) $p['selling_price'])),
            'stock' => (int) $p['stock_qty'],
            'low' => (int) $p['stock_qty'] <= (int) $p['effective_threshold'],
            'image' => $p['image_path'] ? Http::basePath() . '/' . $p['image_path'] : null,
        ];
        Http::json([
            'ok' => true,
            'exact' => $res['exact'] ? $map($res['exact']) : null,
            'results' => array_map($map, $res['results']),
        ]);
    }

    public function checkout(): void
    {
        $body = Http::jsonBody();
        try {
            $result = SaleService::checkout(
                is_array($body['items'] ?? null) ? $body['items'] : [],
                is_string($body['discount_type'] ?? null) ? $body['discount_type'] : 'none',
                is_string($body['discount_value'] ?? null) ? $body['discount_value'] : '0',
                is_string($body['tendered'] ?? null) ? $body['tendered'] : '',
                is_string($body['client_token'] ?? null) ? $body['client_token'] : '',
                Auth::id()
            );
        } catch (ValidationException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            Logger::error('Checkout failed', $e);
            Http::json(['ok' => false, 'error' => 'The sale could not be completed. Nothing was charged or deducted. Please try again.'], 500);
        }
        $sale = $result['sale'];
        Http::json([
            'ok' => true,
            'duplicate' => $result['duplicate'],
            'sale' => [
                'id' => (int) $sale['id'],
                'transaction_no' => $sale['transaction_no'],
                'total' => Money::format(Money::toCents((string) $sale['total'])),
                'tendered' => Money::format(Money::toCents((string) $sale['amount_tendered'])),
                'change' => Money::format(Money::toCents((string) $sale['change_due'])),
            ],
            'receipt_url' => url('sales.receipt', ['id' => $sale['id']]),
        ]);
    }

    public function receipt(): void
    {
        $sale = SaleService::find($this->idParam());
        if ($sale === null) {
            $this->notFound('sale');
        }
        $this->view('pages/receipt', [
            'title' => 'Receipt ' . $sale['transaction_no'],
            'sale' => $sale,
            'autoprint' => Http::query('print') === '1',
        ], 'layout/print');
    }
}
