<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\Money;
use App\Core\ValidationException;

final class SaleService
{
    public const MAX_LINES = 100;
    public const MAX_QTY = 10000;
    public const PER_PAGE = 20;

    /**
     * Complete a cash sale. Prices, totals, stock and change are all computed here from the
     * database; anything the browser sends besides product IDs, quantities, the discount
     * request and the tendered amount is ignored.
     *
     * $clientToken is a UUID generated once per cart by the POS page. Re-submitting the same
     * token (double click, network retry) returns the already-saved sale instead of a new one.
     *
     * @param list<array{product_id:mixed,quantity:mixed}> $items
     * @return array{sale:array,duplicate:bool}
     */
    public static function checkout(
        array $items,
        string $discountType,
        string $discountValue,
        string $tendered,
        string $clientToken,
        int $userId
    ): array {
        self::assertTransactional();
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clientToken)) {
            throw new ValidationException(['cart' => 'Invalid transaction token. Reload the POS page and try again.']);
        }
        $existing = DB::value('SELECT id FROM sales WHERE client_token = ?', [$clientToken]);
        if ($existing !== null) {
            return ['sale' => self::find((int) $existing), 'duplicate' => true];
        }

        // 1. Validate and merge cart lines.
        $qtyById = [];
        foreach ($items as $item) {
            $pid = $item['product_id'] ?? null;
            $qty = $item['quantity'] ?? null;
            if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
                throw new ValidationException(['cart' => 'The cart contains an invalid product.']);
            }
            if (!is_int($qty) && !(is_string($qty) && ctype_digit($qty))) {
                throw new ValidationException(['cart' => 'Quantities must be whole numbers.']);
            }
            $pid = (int) $pid;
            $qty = (int) $qty;
            if ($pid <= 0 || $qty <= 0 || $qty > self::MAX_QTY) {
                throw new ValidationException(['cart' => 'Quantities must be between 1 and ' . self::MAX_QTY . '.']);
            }
            $qtyById[$pid] = ($qtyById[$pid] ?? 0) + $qty;
        }
        if ($qtyById === []) {
            throw new ValidationException(['cart' => 'The cart is empty.']);
        }
        if (count($qtyById) > self::MAX_LINES) {
            throw new ValidationException(['cart' => 'A sale can contain at most ' . self::MAX_LINES . ' different products.']);
        }
        if (!in_array($discountType, ['none', 'amount', 'percent'], true)) {
            throw new ValidationException(['discount' => 'Invalid discount type.']);
        }
        $tenderedCents = Money::parse($tendered);
        if ($tenderedCents === null) {
            throw new ValidationException(['tendered' => 'Enter the amount tendered by the customer.']);
        }

        try {
            $saleId = DB::transaction(static function () use ($qtyById, $discountType, $discountValue, $tenderedCents, $clientToken, $userId): int {
                ksort($qtyById); // consistent lock order prevents deadlocks
                $ids = array_keys($qtyById);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // 2–4. Current prices, availability and stock, locked until commit.
                $products = [];
                foreach (DB::all(
                    "SELECT id, sku, name, unit, selling_price, cost_price, stock_qty, is_active
                       FROM products WHERE id IN ($placeholders) ORDER BY id FOR UPDATE",
                    $ids
                ) as $row) {
                    $products[(int) $row['id']] = $row;
                }

                $problems = [];
                $lines = [];
                $subtotal = 0;
                $itemCount = 0;
                foreach ($qtyById as $pid => $qty) {
                    $p = $products[$pid] ?? null;
                    if ($p === null) {
                        $problems[] = 'A product in the cart no longer exists.';
                        continue;
                    }
                    if ((int) $p['is_active'] !== 1) {
                        $problems[] = $p['name'] . ' is archived and cannot be sold.';
                        continue;
                    }
                    if ((int) $p['stock_qty'] < $qty) {
                        $problems[] = $p['name'] . ': only ' . max(0, (int) $p['stock_qty']) . ' in stock.';
                        continue;
                    }
                    // 5. Totals from database prices.
                    $price = Money::toCents((string) $p['selling_price']);
                    $lineTotal = Money::times($price, $qty);
                    $subtotal += $lineTotal;
                    $itemCount += $qty;
                    $lines[] = [
                        'product' => $p,
                        'qty' => $qty,
                        'price' => $price,
                        'cost' => Money::toCents((string) $p['cost_price']),
                        'line_total' => $lineTotal,
                    ];
                }
                if ($problems) {
                    throw new ValidationException(['cart' => implode(' ', $problems)]);
                }

                $discount = 0;
                if ($discountType === 'amount') {
                    $discount = Money::parse($discountValue);
                    if ($discount === null || $discount > $subtotal) {
                        throw new ValidationException(['discount' => 'The discount must be between 0 and the subtotal.']);
                    }
                } elseif ($discountType === 'percent') {
                    // "12.5" parses to 1250 centi-units == 1250 basis points (12.50%).
                    $bp = Money::parse($discountValue);
                    if ($bp === null || $bp > 10000) {
                        throw new ValidationException(['discount' => 'The discount percentage must be between 0 and 100.']);
                    }
                    $discount = Money::percent($subtotal, $bp);
                }
                $total = $subtotal - $discount;
                if ($total > Money::MAX_CENTS) {
                    throw new ValidationException(['cart' => 'The sale total is too large.']);
                }

                // 6. Validate payment.
                if ($tenderedCents < $total) {
                    throw new ValidationException(['tendered' => 'Insufficient payment: amount due is ' . Money::format($total) . '.']);
                }
                $change = $tenderedCents - $total;

                // 7–8. Unique transaction number and sale record.
                $now = Clock::nowUtc();
                $saleId = 0;
                for ($attempt = 0; $attempt < 5 && $saleId === 0; $attempt++) {
                    $txn = 'MS-' . Clock::nowLocal()->format('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                    try {
                        $saleId = DB::insert(
                            'INSERT INTO sales (transaction_no, client_token, user_id, item_count, subtotal, discount_amount,
                                total, amount_tendered, change_due, payment_method, status, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'cash\', \'completed\', ?)',
                            [
                                $txn, $clientToken, $userId, $itemCount, Money::toDecimal($subtotal), Money::toDecimal($discount),
                                Money::toDecimal($total), Money::toDecimal($tenderedCents), Money::toDecimal($change), $now,
                            ]
                        );
                    } catch (\PDOException $e) {
                        if (!DB::isDuplicateKey($e, 'uq_sales_transaction_no')) {
                            throw $e;
                        }
                    }
                }
                if ($saleId === 0) {
                    throw new \RuntimeException('Could not allocate a unique transaction number.');
                }

                $insertItem = DB::pdo()->prepare(
                    'INSERT INTO sale_items (sale_id, product_id, product_name, sku, unit, quantity, unit_price, unit_cost, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $deduct = DB::pdo()->prepare(
                    'UPDATE products SET stock_qty = stock_qty - ?, updated_at = ? WHERE id = ? AND stock_qty >= ?'
                );
                $movement = DB::pdo()->prepare(
                    'INSERT INTO stock_movements (product_id, user_id, movement_type, qty_before, qty_change, qty_after, reason, sale_id, created_at)
                     VALUES (?, ?, \'sale\', ?, ?, ?, ?, ?, ?)'
                );
                foreach ($lines as $l) {
                    $p = $l['product'];
                    $insertItem->execute([
                        $saleId, $p['id'], $p['name'], $p['sku'], $p['unit'], $l['qty'],
                        Money::toDecimal($l['price']), Money::toDecimal($l['cost']), Money::toDecimal($l['line_total']),
                    ]);
                    // 9. Conditional deduction: never lets stock go negative even without the row lock.
                    $deduct->execute([$l['qty'], $now, $p['id'], $l['qty']]);
                    if ($deduct->rowCount() !== 1) {
                        throw new ValidationException(['cart' => $p['name'] . ' no longer has enough stock.']);
                    }
                    // 10. Inventory movement.
                    $before = (int) $p['stock_qty'];
                    $movement->execute([$p['id'], $userId, $before, -$l['qty'], $before - $l['qty'], 'Sale', $saleId, $now]);
                }
                return $saleId; // 11. commit happens in DB::transaction()
            });
        } catch (\PDOException $e) {
            // Same token submitted concurrently: the other request won; return its sale.
            if (DB::isDuplicateKey($e, 'uq_sales_client_token')) {
                $id = DB::value('SELECT id FROM sales WHERE client_token = ?', [$clientToken]);
                if ($id !== null) {
                    return ['sale' => self::find((int) $id), 'duplicate' => true];
                }
            }
            throw $e;
        }

        // 12. Completed transaction for the receipt.
        return ['sale' => self::find($saleId), 'duplicate' => false];
    }

    /**
     * Sales must never be recorded on non-transactional (MyISAM) tables: a rejected sale could
     * leave its sale and line rows behind without deducting stock. Refuse instead.
     */
    public static function assertTransactional(): void
    {
        $bad = DB::nonTransactionalTables();
        if ($bad !== []) {
            throw new ValidationException(['cart' => 'Sales are paused to protect your records: the database tables ('
                . implode(', ', $bad) . ') do not support transactions. An administrator should open Settings → System Check.']);
        }
    }

    /**
     * Void a completed sale. The original sale and its lines are kept and marked voided; stock is
     * restored and the reversal is recorded, all in one transaction. A sale can be voided once.
     *
     * The quantity returned to stock is what the stock ledger shows was actually deducted for the
     * sale, not what the sale lines say. For normal sales the two are equal; for damaged records
     * left by a failed sale on non-transactional tables (a line whose stock was never deducted)
     * this avoids adding stock that never left the shelf.
     */
    public static function void(int $saleId, string $reason, int $requesterId, int $approverId): void
    {
        self::assertTransactional();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new ValidationException(['reason' => 'Enter the reason for voiding (up to 255 characters).']);
        }
        DB::transaction(static function () use ($saleId, $reason, $requesterId, $approverId): void {
            $sale = DB::one('SELECT id, status FROM sales WHERE id = ? FOR UPDATE', [$saleId]);
            if ($sale === null) {
                throw new ValidationException(['sale' => 'Sale not found.']);
            }
            if ($sale['status'] !== 'completed') {
                throw new ValidationException(['sale' => 'This sale has already been voided.']);
            }
            $now = Clock::nowUtc();
            $deducted = DB::all(
                "SELECT product_id, -SUM(qty_change) AS quantity FROM stock_movements
                  WHERE sale_id = ? AND movement_type = 'sale' GROUP BY product_id ORDER BY product_id",
                [$saleId]
            );
            foreach ($deducted as $it) {
                $qty = (int) $it['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                $p = DB::one('SELECT stock_qty FROM products WHERE id = ? FOR UPDATE', [$it['product_id']]);
                $before = (int) $p['stock_qty'];
                DB::run('UPDATE products SET stock_qty = stock_qty + ?, updated_at = ? WHERE id = ?', [$qty, $now, $it['product_id']]);
                DB::run(
                    'INSERT INTO stock_movements (product_id, user_id, movement_type, qty_before, qty_change, qty_after, reason, sale_id, created_at)
                     VALUES (?, ?, \'void\', ?, ?, ?, ?, ?, ?)',
                    [$it['product_id'], $requesterId, $before, $qty, $before + $qty, 'Void: ' . mb_substr($reason, 0, 240), $saleId, $now]
                );
            }
            $updated = DB::run(
                'UPDATE sales SET status = \'voided\', void_reason = ?, voided_by = ?, void_approved_by = ?, voided_at = ? WHERE id = ? AND status = \'completed\'',
                [$reason, $requesterId, $approverId, $now, $saleId]
            )->rowCount();
            if ($updated !== 1) {
                throw new ValidationException(['sale' => 'This sale has already been voided.']);
            }
        });
    }

    public static function find(int $id): ?array
    {
        $sale = DB::one(
            'SELECT s.*, u.username AS cashier, v.username AS voided_by_name, a.username AS void_approved_by_name
               FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN users v ON v.id = s.voided_by
               LEFT JOIN users a ON a.id = s.void_approved_by
              WHERE s.id = ?',
            [$id]
        );
        if ($sale === null) {
            return null;
        }
        $sale['items'] = DB::all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
        return $sale;
    }

    /** @return array{rows:array,total:int,page:int,pages:int} */
    public static function list(array $f): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.transaction_no LIKE ? OR EXISTS (SELECT 1 FROM sale_items si WHERE si.sale_id = s.id AND (si.product_name LIKE ? OR si.sku LIKE ?)))';
            $like = '%' . ProductService::escapeLike($q) . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($f['from_utc']) && !empty($f['to_utc'])) {
            $where[] = 's.created_at >= ? AND s.created_at < ?';
            array_push($params, $f['from_utc'], $f['to_utc']);
        }
        if (in_array($f['status'] ?? '', ['completed', 'voided'], true)) {
            $where[] = 's.status = ?';
            $params[] = $f['status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) DB::value("SELECT COUNT(*) FROM sales s $whereSql", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = DB::all(
            "SELECT s.id, s.transaction_no, s.created_at, s.item_count, s.total, s.payment_method, s.status, u.username AS cashier
               FROM sales s JOIN users u ON u.id = s.user_id
               $whereSql ORDER BY s.created_at DESC, s.id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
