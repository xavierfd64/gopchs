<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\Money;
use App\Core\Settings;
use App\Core\ValidationException;

final class ProductService
{
    public const PER_PAGE = 20;
    public const MAX_STOCK = 1000000;

    public const SORTS = [
        'name_asc' => 'p.name ASC',
        'name_desc' => 'p.name DESC',
        'sku_asc' => 'p.sku ASC',
        'price_asc' => 'p.selling_price ASC, p.name ASC',
        'price_desc' => 'p.selling_price DESC, p.name ASC',
        'stock_asc' => 'p.stock_qty ASC, p.name ASC',
        'stock_desc' => 'p.stock_qty DESC, p.name ASC',
        'updated_desc' => 'p.updated_at DESC',
    ];

    public static function defaultThreshold(): int
    {
        return max(0, (int) Settings::get('low_stock_threshold', '5'));
    }

    /** SQL expression for a product's effective low-stock threshold. */
    public static function thresholdSql(): string
    {
        return 'COALESCE(p.low_stock_threshold, ' . self::defaultThreshold() . ')';
    }

    /**
     * Validate product form input. Returns normalized data.
     * @throws ValidationException
     */
    public static function validate(array $in, bool $isNew): array
    {
        $errors = [];
        $str = static fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';

        $name = $str('name');
        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = 'Enter a product name (up to 150 characters).';
        }
        $sku = strtoupper($str('sku'));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\-\/]{0,63}$/', $sku)) {
            $errors['sku'] = 'SKU is required: letters, numbers, dot, dash, slash or underscore (max 64).';
        }
        $barcode = $str('barcode');
        if ($barcode !== '' && !preg_match('/^[\x21-\x7E]{1,64}$/', $barcode)) {
            $errors['barcode'] = 'Barcode may contain up to 64 printable characters without spaces.';
        }
        $category = preg_replace('/\s+/', ' ', $str('category')) ?? '';
        if (mb_strlen($category) > 80) {
            $errors['category'] = 'Category names are limited to 80 characters.';
        }
        $description = $str('description');
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'Description is limited to 2000 characters.';
        }
        $unit = $str('unit') === '' ? 'pc' : $str('unit');
        if (mb_strlen($unit) > 20) {
            $errors['unit'] = 'Unit is limited to 20 characters.';
        }
        $cost = Money::parse($str('cost_price') === '' ? '0' : $str('cost_price'));
        if ($cost === null) {
            $errors['cost_price'] = 'Enter a valid cost price (0 or more, up to 2 decimals).';
        }
        $price = Money::parse($str('selling_price'));
        if ($price === null) {
            $errors['selling_price'] = 'Enter a valid selling price (0 or more, up to 2 decimals).';
        }
        $threshold = null;
        if ($str('low_stock_threshold') !== '') {
            if (!preg_match('/^\d{1,6}$/', $str('low_stock_threshold'))) {
                $errors['low_stock_threshold'] = 'Low-stock threshold must be a whole number, or blank to use the default.';
            } else {
                $threshold = (int) $str('low_stock_threshold');
            }
        }
        $stock = 0;
        if ($isNew) {
            $s = $str('stock_qty') === '' ? '0' : $str('stock_qty');
            if (!preg_match('/^\d{1,7}$/', $s) || (int) $s > self::MAX_STOCK) {
                $errors['stock_qty'] = 'Opening stock must be a whole number from 0 to ' . number_format(self::MAX_STOCK) . '.';
            } else {
                $stock = (int) $s;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return [
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode === '' ? null : $barcode,
            'category' => $category,
            'description' => $description === '' ? null : $description,
            'unit' => $unit,
            'cost_price' => Money::toDecimal((int) $cost),
            'selling_price' => Money::toDecimal((int) $price),
            'low_stock_threshold' => $threshold,
            'stock_qty' => $stock,
        ];
    }

    private static function assertUnique(array $d, ?int $exceptId): void
    {
        $errors = [];
        $id = $exceptId ?? 0;
        if (DB::value('SELECT id FROM products WHERE sku = ? AND id <> ?', [$d['sku'], $id]) !== null) {
            $errors['sku'] = 'Another product already uses this SKU.';
        }
        if ($d['barcode'] !== null && DB::value('SELECT id FROM products WHERE barcode = ? AND id <> ?', [$d['barcode'], $id]) !== null) {
            $errors['barcode'] = 'Another product already uses this barcode.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    private static function duplicateError(\Throwable $e): ValidationException
    {
        if (DB::isDuplicateKey($e, 'uq_products_barcode')) {
            return new ValidationException(['barcode' => 'Another product already uses this barcode.']);
        }
        return new ValidationException(['sku' => 'Another product already uses this SKU.']);
    }

    public static function categoryId(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $id = DB::value('SELECT id FROM categories WHERE name = ?', [$name]);
        if ($id !== null) {
            return (int) $id;
        }
        try {
            return DB::insert('INSERT INTO categories (name, created_at) VALUES (?, ?)', [$name, Clock::nowUtc()]);
        } catch (\PDOException $e) {
            if (DB::isDuplicateKey($e)) {
                return (int) DB::value('SELECT id FROM categories WHERE name = ?', [$name]);
            }
            throw $e;
        }
    }

    public static function categories(): array
    {
        return DB::all('SELECT id, name FROM categories ORDER BY name');
    }

    public static function create(array $d, int $userId, ?string $imagePath = null): int
    {
        self::assertUnique($d, null);
        try {
            return DB::transaction(static function () use ($d, $userId, $imagePath): int {
                $now = Clock::nowUtc();
                $id = DB::insert(
                    'INSERT INTO products (sku, barcode, name, category_id, description, unit, cost_price, selling_price,
                        stock_qty, low_stock_threshold, image_path, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                    [
                        $d['sku'], $d['barcode'], $d['name'], self::categoryId($d['category']), $d['description'],
                        $d['unit'], $d['cost_price'], $d['selling_price'], $d['stock_qty'],
                        $d['low_stock_threshold'], $imagePath, $now, $now,
                    ]
                );
                if ($d['stock_qty'] > 0) {
                    DB::run(
                        'INSERT INTO stock_movements (product_id, user_id, movement_type, qty_before, qty_change, qty_after, reason, created_at)
                         VALUES (?, ?, \'initial\', 0, ?, ?, ?, ?)',
                        [$id, $userId, $d['stock_qty'], $d['stock_qty'], 'Opening stock', $now]
                    );
                }
                return $id;
            });
        } catch (\PDOException $e) {
            if (DB::isDuplicateKey($e)) {
                throw self::duplicateError($e);
            }
            throw $e;
        }
    }

    /** Update product details. Stock is never changed here; use InventoryService::adjust(). */
    public static function update(int $id, array $d, ?string $newImagePath = null, bool $removeImage = false): void
    {
        $current = self::find($id);
        if ($current === null) {
            throw new ValidationException(['id' => 'Product not found.']);
        }
        self::assertUnique($d, $id);
        $image = $current['image_path'];
        if ($newImagePath !== null || $removeImage) {
            $image = $newImagePath;
        }
        try {
            DB::run(
                'UPDATE products SET sku = ?, barcode = ?, name = ?, category_id = ?, description = ?, unit = ?,
                    cost_price = ?, selling_price = ?, low_stock_threshold = ?, image_path = ?, updated_at = ?
                 WHERE id = ?',
                [
                    $d['sku'], $d['barcode'], $d['name'], self::categoryId($d['category']), $d['description'], $d['unit'],
                    $d['cost_price'], $d['selling_price'], $d['low_stock_threshold'], $image, Clock::nowUtc(), $id,
                ]
            );
        } catch (\PDOException $e) {
            if (DB::isDuplicateKey($e)) {
                throw self::duplicateError($e);
            }
            throw $e;
        }
        if ($image !== $current['image_path']) {
            ImageUpload::delete($current['image_path']);
        }
    }

    public static function setActive(int $id, bool $active): bool
    {
        return DB::run('UPDATE products SET is_active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, Clock::nowUtc(), $id])
            ->rowCount() > 0;
    }

    public static function find(int $id): ?array
    {
        return DB::one(
            'SELECT p.*, c.name AS category_name, ' . self::thresholdSql() . ' AS effective_threshold
               FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?',
            [$id]
        );
    }

    /**
     * Filtered, sorted, paginated product list.
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public static function list(array $f): array
    {
        $where = [];
        $params = [];
        $status = $f['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = 'p.is_active = 1';
        } elseif ($status === 'archived') {
            $where[] = 'p.is_active = 0';
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR c.name LIKE ?)';
            $like = '%' . self::escapeLike($q) . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $f['category_id'];
        }
        $stock = $f['stock'] ?? '';
        if ($stock === 'low') {
            $where[] = 'p.stock_qty > 0 AND p.stock_qty <= ' . self::thresholdSql();
        } elseif ($stock === 'out') {
            $where[] = 'p.stock_qty <= 0';
        } elseif ($stock === 'in') {
            $where[] = 'p.stock_qty > ' . self::thresholdSql();
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $order = self::SORTS[$f['sort'] ?? ''] ?? self::SORTS['name_asc'];

        $total = (int) DB::value("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id $whereSql", $params);
        $perPage = (int) ($f['per_page'] ?? self::PER_PAGE);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = DB::all(
            "SELECT p.*, c.name AS category_name, " . self::thresholdSql() . " AS effective_threshold
               FROM products p LEFT JOIN categories c ON c.id = p.category_id
               $whereSql ORDER BY $order, p.id ASC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * POS search: active products by exact barcode/SKU first, then partial name/SKU/barcode/category.
     * @return array{exact:?array, results:array}
     */
    public static function searchForPos(string $q, int $categoryId = 0, int $limit = 40): array
    {
        $q = mb_substr(trim($q), 0, 100);
        if ($q === '') {
            return ['exact' => null, 'results' => []];
        }
        $cols = 'p.id, p.sku, p.barcode, p.name, p.unit, p.selling_price, p.stock_qty, p.image_path, c.name AS category_name, ' . self::thresholdSql() . ' AS effective_threshold';
        $exact = DB::one(
            "SELECT $cols FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.is_active = 1 AND (p.barcode = ? OR p.sku = ?) ORDER BY (p.barcode = ?) DESC LIMIT 1",
            [$q, strtoupper($q), $q]
        );
        $like = '%' . self::escapeLike($q) . '%';
        $results = DB::all(
            "SELECT $cols FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.is_active = 1 AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR c.name LIKE ?)
                AND (? = 0 OR p.category_id = ?)
              ORDER BY (p.name LIKE ?) DESC, p.name ASC LIMIT $limit",
            [$like, $like, $like, $like, $categoryId, $categoryId, self::escapeLike($q) . '%']
        );
        return ['exact' => $exact, 'results' => $results];
    }

    /** Active products for the POS grid, optionally limited to one category. */
    public static function browseForPos(int $categoryId = 0, int $limit = 60): array
    {
        return DB::all(
            "SELECT p.id, p.sku, p.barcode, p.name, p.unit, p.selling_price, p.stock_qty, p.image_path, c.name AS category_name,
                    " . self::thresholdSql() . " AS effective_threshold
               FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.is_active = 1 AND (? = 0 OR p.category_id = ?)
              ORDER BY (p.stock_qty > 0) DESC, p.name ASC LIMIT $limit",
            [$categoryId, $categoryId]
        );
    }

    public static function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    public static function stockStatus(array $p): string
    {
        $qty = (int) $p['stock_qty'];
        $threshold = (int) ($p['effective_threshold'] ?? $p['low_stock_threshold'] ?? self::defaultThreshold());
        if ($qty <= 0) {
            return 'out';
        }
        return $qty <= $threshold ? 'low' : 'in';
    }
}
