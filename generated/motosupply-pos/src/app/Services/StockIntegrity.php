<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Read-only audit of inventory consistency. Nothing here changes data; corrections are made
 * through audited actions (stock count adjustment, approved void) chosen by a person.
 *
 * Open issues (each can be resolved by an audited correction):
 *  - negative:  products whose stock is below zero (impossible with the 1.3 database guard)
 *  - ledger:    products whose stock differs from the "after" quantity of their latest stock
 *               movement (stock was changed without being recorded); resolved by a physical count
 *  - phantom:   completed sales with a line that has no matching stock deduction (left behind
 *               by a failed sale on non-transactional tables); resolved by an approved void or by
 *               marking the record reviewed
 * History, shown for review only (records are never rewritten):
 *  - oversold:  past sale movements that took stock below zero
 *  - chain:     movements whose "after" does not equal "before + change"
 */
final class StockIntegrity
{
    public static function scan(): array
    {
        $negative = DB::all('SELECT id, sku, name, stock_qty FROM products WHERE stock_qty < 0 ORDER BY name');
        $ledger = DB::all(
            'SELECT p.id, p.sku, p.name, p.stock_qty, COALESCE(last.qty_after, 0) AS ledger_qty, last.created_at AS last_movement
               FROM products p
               LEFT JOIN stock_movements last ON last.id = (SELECT MAX(m.id) FROM stock_movements m WHERE m.product_id = p.id)
              WHERE CAST(p.stock_qty AS SIGNED) <> CAST(COALESCE(last.qty_after, 0) AS SIGNED)
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
                AND NOT EXISTS (SELECT 1 FROM integrity_reviews r WHERE r.kind = 'phantom' AND r.ref_id = s.id)
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
            'issues' => count($negative) + count($ledger) + count($phantom),
            'history' => count($chain) + count($oversold),
        ];
    }

    /**
     * Close a "sale without stock deduction" finding when the goods WERE handed over: the sale
     * stays as it is (the product count is corrected separately). Recorded with the reviewer's note.
     */
    public static function markReviewed(string $kind, int $refId, string $note, int $userId): void
    {
        $note = trim($note);
        if ($kind !== 'phantom' || $note === '' || mb_strlen($note) > 255) {
            throw new \App\Core\ValidationException(['note' => 'Enter a note explaining the review (up to 255 characters).']);
        }
        if (DB::value("SELECT id FROM sales WHERE id = ? AND status = 'completed'", [$refId]) === null) {
            throw new \App\Core\ValidationException(['sale' => 'Sale not found or already voided.']);
        }
        DB::run('INSERT IGNORE INTO integrity_reviews (kind, ref_id, note, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$kind, $refId, $note, $userId, \App\Core\Clock::nowUtc()]);
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
