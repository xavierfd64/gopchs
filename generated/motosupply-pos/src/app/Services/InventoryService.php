<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\ValidationException;

final class InventoryService
{
    public const MODES = ['add' => 'Add stock', 'remove' => 'Remove stock', 'set' => 'Set exact count'];

    /**
     * Manually adjust stock. Locks the product row so concurrent sales and adjustments
     * are serialized, and records a stock movement with before/after quantities.
     * @return array{qty_before:int,qty_change:int,qty_after:int}
     */
    public static function adjust(int $productId, string $mode, string $qtyInput, string $reason, int $userId): array
    {
        $errors = [];
        if (!isset(self::MODES[$mode])) {
            $errors['mode'] = 'Choose how to adjust the stock.';
        }
        $qtyInput = trim($qtyInput);
        if (!preg_match('/^\d{1,7}$/', $qtyInput) || (int) $qtyInput > ProductService::MAX_STOCK) {
            $errors['quantity'] = 'Enter a whole number quantity.';
        } elseif ($mode !== 'set' && (int) $qtyInput === 0) {
            $errors['quantity'] = 'Quantity must be greater than zero.';
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            $errors['reason'] = 'Enter a reason for this adjustment (up to 255 characters).';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $qty = (int) $qtyInput;
        SaleService::assertTransactional();

        return DB::transaction(static function () use ($productId, $mode, $qty, $reason, $userId): array {
            $row = DB::one('SELECT id, stock_qty FROM products WHERE id = ? FOR UPDATE', [$productId]);
            if ($row === null) {
                throw new ValidationException(['product' => 'Product not found.']);
            }
            $before = (int) $row['stock_qty'];
            $after = match ($mode) {
                'add' => $before + $qty,
                'remove' => $before - $qty,
                'set' => $qty,
            };
            if ($after < 0) {
                throw new ValidationException(['quantity' => "Cannot remove more than the current stock ($before)."]);
            }
            if ($after > ProductService::MAX_STOCK) {
                throw new ValidationException(['quantity' => 'Resulting stock is too large.']);
            }
            $change = $after - $before;
            if ($change === 0) {
                throw new ValidationException(['quantity' => 'The stock count is already ' . $before . '; nothing to change.']);
            }
            $now = Clock::nowUtc();
            DB::run('UPDATE products SET stock_qty = ?, updated_at = ? WHERE id = ?', [$after, $now, $productId]);
            DB::run(
                'INSERT INTO stock_movements (product_id, user_id, movement_type, qty_before, qty_change, qty_after, reason, created_at)
                 VALUES (?, ?, \'adjustment\', ?, ?, ?, ?, ?)',
                [$productId, $userId, $before, $change, $after, $reason, $now]
            );
            return ['qty_before' => $before, 'qty_change' => $change, 'qty_after' => $after];
        });
    }

    /**
     * Stock movement history, optionally filtered by product and UTC range.
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public static function movements(array $f, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($f['product_id'])) {
            $where[] = 'm.product_id = ?';
            $params[] = (int) $f['product_id'];
        }
        if (!empty($f['from_utc']) && !empty($f['to_utc'])) {
            $where[] = 'm.created_at >= ? AND m.created_at < ?';
            array_push($params, $f['from_utc'], $f['to_utc']);
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) DB::value("SELECT COUNT(*) FROM stock_movements m $whereSql", $params);
        // $perPage = 0 returns every row (used by report exports).
        $pages = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;
        $limit = $perPage > 0 ? "LIMIT $perPage OFFSET $offset" : 'LIMIT 20000';
        $rows = DB::all(
            "SELECT m.*, p.name AS product_name, p.sku, u.username, s.transaction_no
               FROM stock_movements m
               JOIN products p ON p.id = m.product_id
               JOIN users u ON u.id = m.user_id
               LEFT JOIN sales s ON s.id = m.sale_id
               $whereSql ORDER BY m.created_at DESC, m.id DESC $limit",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
