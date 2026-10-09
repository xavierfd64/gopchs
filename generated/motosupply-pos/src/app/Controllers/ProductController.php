<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Http;
use App\Core\ValidationException;
use App\Services\ImageUpload;
use App\Services\InventoryService;
use App\Services\ProductService;

final class ProductController extends Controller
{
    private function filters(): array
    {
        $status = Http::query('status', 'active');
        $stock = Http::query('stock');
        $sort = Http::query('sort', 'name_asc');
        return [
            'q' => mb_substr(Http::query('q'), 0, 100),
            'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
            'stock' => in_array($stock, ['low', 'out', 'in'], true) ? $stock : '',
            'category_id' => $this->idParam('category') ?: null,
            'sort' => isset(ProductService::SORTS[$sort]) ? $sort : 'name_asc',
            'page' => $this->idParam('page') ?: 1,
        ];
    }

    public function index(): void
    {
        $f = $this->filters();
        $t = ProductService::thresholdSql();
        $stats = DB::one(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT p.category_id) AS categories,
                    COALESCE(SUM(GREATEST(p.stock_qty, 0) * p.cost_price), 0) AS value,
                    COALESCE(SUM(p.stock_qty > 0 AND p.stock_qty <= $t), 0) AS low,
                    COALESCE(SUM(p.stock_qty <= 0), 0) AS out_of_stock
               FROM products p WHERE p.is_active = 1"
        );
        $this->view('pages/products', [
            'title' => 'Products / Inventory',
            'nav' => 'products',
            'f' => $f,
            'list' => ProductService::list($f),
            'categories' => ProductService::categories(),
            'stats' => $stats,
        ]);
    }

    public function form(): void
    {
        [$old, $errors] = $this->takeOld();
        $product = null;
        $id = $this->idParam();
        if ($id > 0) {
            $product = ProductService::find($id);
            if ($product === null) {
                $this->notFound('product');
            }
        }
        $values = $old ?: ($product ? [
            'name' => $product['name'],
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'category' => $product['category_name'],
            'description' => $product['description'],
            'unit' => $product['unit'],
            'cost_price' => $product['cost_price'],
            'selling_price' => $product['selling_price'],
            'low_stock_threshold' => $product['low_stock_threshold'],
        ] : ['unit' => 'pc', 'cost_price' => '', 'selling_price' => '', 'stock_qty' => '0']);
        $this->view('pages/product_form', [
            'title' => $product ? 'Edit product' : 'Add product',
            'nav' => 'products',
            'product' => $product,
            'v' => $values,
            'errors' => $errors,
            'categories' => ProductService::categories(),
            'defaultThreshold' => ProductService::defaultThreshold(),
        ]);
    }

    public function save(): void
    {
        $id = $this->idParam('id', 'post');
        $isNew = $id === 0;
        $input = array_map(static fn ($v) => is_string($v) ? $v : '', $_POST);
        $image = null;
        try {
            $data = ProductService::validate($input, $isNew);
            $image = ImageUpload::store($_FILES['image'] ?? null);
            if ($isNew) {
                $id = ProductService::create($data, Auth::id(), $image);
                Http::flash('success', 'Product "' . $data['name'] . '" was created.');
            } else {
                ProductService::update($id, $data, $image, Http::post('remove_image') === '1');
                Http::flash('success', 'Product "' . $data['name'] . '" was updated.');
            }
        } catch (ValidationException $e) {
            ImageUpload::delete($image);
            unset($input['_csrf']);
            $this->withOld($input, $e->errors);
            Http::flash('error', 'Please correct the highlighted fields.');
            Http::redirect($isNew ? url('products.create') : url('products.edit', ['id' => $id]));
        }
        Http::redirect(url('products'));
    }

    public function archive(): void
    {
        $id = $this->idParam('id', 'post');
        if (ProductService::setActive($id, false)) {
            Http::flash('success', 'Product archived. It can no longer be sold and can be restored later.');
        }
        Http::redirect(url('products'));
    }

    public function restore(): void
    {
        $id = $this->idParam('id', 'post');
        if (ProductService::setActive($id, true)) {
            Http::flash('success', 'Product restored.');
        }
        Http::redirect(url('products', ['status' => 'archived']));
    }

    public function adjust(): void
    {
        $id = Http::isPost() ? $this->idParam('id', 'post') : $this->idParam();
        $product = ProductService::find($id);
        if ($product === null) {
            $this->notFound('product');
        }
        $errors = [];
        $v = ['mode' => 'add', 'quantity' => '', 'reason' => ''];
        if (Http::isPost()) {
            $v = ['mode' => Http::post('mode'), 'quantity' => Http::post('quantity'), 'reason' => Http::post('reason')];
            try {
                $r = InventoryService::adjust($id, $v['mode'], $v['quantity'], $v['reason'], Auth::id());
                Http::flash('success', sprintf(
                    'Stock for "%s" changed from %d to %d.',
                    $product['name'], $r['qty_before'], $r['qty_after']
                ));
                Http::redirect(url('products.movements', ['id' => $id]));
            } catch (ValidationException $e) {
                $errors = $e->errors;
            }
        }
        $this->view('pages/product_adjust', [
            'title' => 'Adjust stock',
            'nav' => 'products',
            'product' => $product,
            'v' => $v,
            'errors' => $errors,
        ]);
    }

    public function movements(): void
    {
        $product = ProductService::find($this->idParam());
        if ($product === null) {
            $this->notFound('product');
        }
        $this->view('pages/product_movements', [
            'title' => 'Stock history',
            'nav' => 'products',
            'product' => $product,
            'list' => InventoryService::movements(['product_id' => $product['id'], 'page' => $this->idParam('page') ?: 1]),
        ]);
    }
}
