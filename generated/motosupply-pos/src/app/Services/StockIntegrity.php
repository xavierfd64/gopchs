<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Read-only audit of inventory consistency. Nothing here changes data; corrections are made
 * through audited actions (stock count adjustment, approved void) chosen by a person.
 *
 *  - negative:  products whose stock is below zero (impossible with the 1.3 database guard)
 *  - ledger:    products whose stock differs from the sum of their stock movements
 *  - chain:     movements whose "after" does not equal "before + change"
 *  - phantom:   completed sales with a line that has no matching stock deduction (left behind
 *               by a failed sale on non-transactional tables)
 *  - oversold:  past sale movements that took stock below zero
 */
final class StockIntegrity
{
    public static function scan(): array
    {
        $negative = DB::all('SELECT id, sku, name, stock_qty FROM products WHERE stock_qty < 0 ORDER BY name');
        $ledger = DB::all(
            'SELECT p.id, p.sku, p.name, p.stock_qty, COALESCE(m.total, 0) AS ledger_qty, COALESCE(m.cnt, 0) AS movements
               FROM products p
               LEFT JOIN (SELECT product_id, SUM(qty_change) AS total, COUNT(*) AS cnt FROM stock_movements GROUP BY product_id) m
                      ON m.product_id = p.id
              WHERE CAST(p.stock_qty AS SIGNED) <> CAST(COALESCE(m.total, 0) AS SIGNED)
              ORDER BY p.name LIMIT 500'
        );
        $chain = DB::all(
            'SELECT m.id, m.product_id, p.sku, p.name, m.movement_type, m.qty_before, m.qty_change, m.qty_after, m.created_at
               FROM stock_movements m JOIN products p ON p.id = m.product_id
              WHERE m.qty_after <> m.qty_before + m.qty_change ORDER BY m.id LIMIT 500'
        );
        $phantom = DB::all(
            "SELECT s.id, s.transaction_no, s.created_at, s.total, si.product_id, si.product_name, si.quantity
               FROM sales s JOIN sale_items si ON si.sale_id = s.id
              WHERE s.status = 'completed'
                AND NOT EXISTS (SELECT 1 FROM stock_movements m
                                 WHERE m.sale_id = s.id AND m.product_id = si.product_id AND m.movement_type = 'sale')
              ORDER BY s.id LIMIT 500"
        );
        $oversold = DB::all(
            "SELECT m.id, p.sku, p.name, m.qty_before, m.qty_change, m.qty_after, m.created_at, s.transaction_no, s.id AS sale_id
               FROM stock_movements m JOIN products p ON p.id = m.product_id LEFT JOIN sales s ON s.id = m.sale_id
              WHERE m.movement_type = 'sale' AND m.qty_after < 0 ORDER BY m.id LIMIT 500"
        );
        return [
            'negative' => $negative,
            'ledger' => $ledger,
            'chain' => $chain,
            'phantom' => $phantom,
            'oversold' => $oversold,
            'guard' => self::guardEnabled(),
            'non_transactional' => DB::nonTransactionalTables(),
            'issues' => count($negative) + count($ledger) + count($chain) + count($phantom) + count($oversold),
        ];
    }

    /** True when the database itself rejects negative stock (stock_qty is UNSIGNED). */
    public static function guardEnabled(): bool
    {
        $type = (string) DB::value(
            "SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'stock_qty'"
        );
        return str_contains(strtolower($type), 'unsigned');
    }

    /** Turn on the database guard once no product has negative stock. */
    public static function enableGuard(): void
    {
        if ((int) DB::value('SELECT COUNT(*) FROM products WHERE stock_qty < 0') > 0) {
            throw new \App\Core\ValidationException(['guard' => 'Correct the products with negative stock first (enter their physical count).']);
        }
        if (!self::guardEnabled()) {
            DB::pdo()->exec('ALTER TABLE products MODIFY stock_qty INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
}
