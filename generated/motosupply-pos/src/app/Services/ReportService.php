<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\Money;
use DateTimeImmutable;

/**
 * Report calculations. Definitions used throughout the system:
 *  - Gross sales   = sum of line totals (subtotals) of completed, non-voided sales
 *  - Discounts     = sum of discounts on those sales
 *  - Net sales     = Gross sales − Discounts  (= sum of sale totals; this is revenue)
 *  - Voided sales  = reported separately and excluded from gross/net
 *  - Cost of goods = sum(quantity × unit cost captured at the time of sale)
 *  - Gross profit  = Net sales − Cost of goods  (profit, not revenue)
 *
 * A report is a table definition rendered identically to HTML, CSV and PDF:
 *   columns: list of [key, label, type] where type is text|int|money|datetime|date
 *   rows:    list of key => raw value (money as DECIMAL strings, datetimes as UTC)
 */
final class ReportService
{
    public const TYPES = [
        'sales' => 'Sales summary',
        'transactions' => 'Transactions',
        'product_sales' => 'Sales by product',
        'inventory' => 'Current inventory & valuation',
        'low_stock' => 'Low-stock products',
        'out_of_stock' => 'Out-of-stock products',
        'movements' => 'Stock movements',
    ];
    public const GROUPS = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'];
    /** Reports that depend on a date range. */
    public const DATED = ['sales', 'transactions', 'product_sales', 'movements'];

    /** Totals for completed and voided sales within a UTC range. All money in centavos. */
    public static function summary(string $fromUtc, string $toUtc): array
    {
        $row = DB::one(
            "SELECT
                COALESCE(SUM(status = 'completed'), 0) AS txn_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN subtotal END), 0) AS gross,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN discount_amount END), 0) AS discounts,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total END), 0) AS net,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN item_count END), 0) AS items,
                COALESCE(SUM(status = 'voided'), 0) AS void_count,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total END), 0) AS void_total
               FROM sales WHERE created_at >= ? AND created_at < ?",
            [$fromUtc, $toUtc]
        );
        $cogs = (string) DB::value(
            "SELECT COALESCE(SUM(si.quantity * si.unit_cost), 0)
               FROM sale_items si JOIN sales s ON s.id = si.sale_id
              WHERE s.status = 'completed' AND s.created_at >= ? AND s.created_at < ?",
            [$fromUtc, $toUtc]
        );
        $net = Money::toCents((string) $row['net']);
        $cogsCents = Money::toCents($cogs);
        return [
            'txn_count' => (int) $row['txn_count'],
            'items' => (int) $row['items'],
            'gross' => Money::toCents((string) $row['gross']),
            'discounts' => Money::toCents((string) $row['discounts']),
            'net' => $net,
            'void_count' => (int) $row['void_count'],
            'void_total' => Money::toCents((string) $row['void_total']),
            'cogs' => $cogsCents,
            'profit' => $net - $cogsCents,
        ];
    }

    /** Offset in seconds of the shop timezone at the given local date (used for grouping by local day). */
    private static function offsetSeconds(string $localDate): int
    {
        return (new DateTimeImmutable($localDate . ' 12:00:00', Clock::tz()))->getOffset();
    }

    /** Validate/normalize the report request. */
    public static function normalize(array $in): array
    {
        $type = isset(self::TYPES[$in['type'] ?? '']) ? $in['type'] : 'sales';
        $group = isset(self::GROUPS[$in['group'] ?? '']) ? $in['group'] : 'day';
        $today = Clock::todayLocal();
        $preset = (string) ($in['preset'] ?? '');
        $from = (string) ($in['from'] ?? '');
        $to = (string) ($in['to'] ?? '');
        $now = Clock::nowLocal();
        switch ($preset) {
            case 'today':
                $from = $to = $today;
                $group = 'day';
                break;
            case 'week':
                $from = $now->modify('monday this week')->format('Y-m-d');
                $to = $today;
                break;
            case 'month':
                $from = $now->format('Y-m-01');
                $to = $today;
                break;
            case 'year':
                $from = $now->format('Y-01-01');
                $to = $today;
                $group = $group === 'day' ? 'month' : $group;
                break;
        }
        if (!Clock::isDate($from)) {
            $from = $now->modify('-6 days')->format('Y-m-d');
        }
        if (!Clock::isDate($to)) {
            $to = $today;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // Keep reports bounded on slow shared hosting.
        $max = (new DateTimeImmutable($from))->modify('+366 days')->format('Y-m-d');
        if ($to > $max) {
            $to = $max;
        }
        return ['type' => $type, 'group' => $group, 'from' => $from, 'to' => $to, 'preset' => $preset];
    }

    public static function rangeLabel(string $from, string $to): string
    {
        $f = (new DateTimeImmutable($from))->format('M j, Y');
        $t = (new DateTimeImmutable($to))->format('M j, Y');
        return $from === $to ? $f : "$f – $t";
    }

    /** Build a report table definition. */
    public static function build(array $req): array
    {
        [$fromUtc, $toUtc] = Clock::localRangeToUtc($req['from'], $req['to']);
        $dated = in_array($req['type'], self::DATED, true);
        $report = [
            'type' => $req['type'],
            'title' => self::TYPES[$req['type']] . ($req['type'] === 'sales' ? ' (' . self::GROUPS[$req['group']] . ')' : ''),
            'range' => $dated ? self::rangeLabel($req['from'], $req['to']) : 'As of ' . Clock::nowLocal()->format('M j, Y g:i A'),
            'columns' => [],
            'rows' => [],
            'summary' => [],
            'notes' => [],
        ];
        return match ($req['type']) {
            'sales' => self::salesReport($report, $req, $fromUtc, $toUtc),
            'transactions' => self::transactionsReport($report, $fromUtc, $toUtc),
            'product_sales' => self::productSalesReport($report, $fromUtc, $toUtc),
            'inventory' => self::inventoryReport($report),
            'low_stock' => self::stockReport($report, 'low'),
            'out_of_stock' => self::stockReport($report, 'out'),
            'movements' => self::movementsReport($report, $fromUtc, $toUtc),
        };
    }

    private static function summaryRows(array $s): array
    {
        return [
            ['Transactions', $s['txn_count'], 'int'],
            ['Items sold', $s['items'], 'int'],
            ['Gross sales', Money::toDecimal($s['gross']), 'money'],
            ['Discounts', Money::toDecimal($s['discounts']), 'money'],
            ['Net sales (revenue)', Money::toDecimal($s['net']), 'money'],
            ['Cost of goods sold', Money::toDecimal($s['cogs']), 'money'],
            ['Gross profit', Money::toDecimal($s['profit']), 'money'],
            ['Voided transactions', $s['void_count'], 'int'],
            ['Voided amount (excluded)', Money::toDecimal($s['void_total']), 'money'],
        ];
    }

    private static function salesReport(array $r, array $req, string $fromUtc, string $toUtc): array
    {
        $off = self::offsetSeconds($req['from']);
        $daily = DB::all(
            "SELECT DATE(DATE_ADD(s.created_at, INTERVAL $off SECOND)) AS d,
                    SUM(s.status = 'completed') AS txn_count,
                    SUM(CASE WHEN s.status = 'completed' THEN s.subtotal ELSE 0 END) AS gross,
                    SUM(CASE WHEN s.status = 'completed' THEN s.discount_amount ELSE 0 END) AS discounts,
                    SUM(CASE WHEN s.status = 'completed' THEN s.total ELSE 0 END) AS net,
                    SUM(s.status = 'voided') AS void_count
               FROM sales s
              WHERE s.created_at >= ? AND s.created_at < ?
              GROUP BY d ORDER BY d",
            [$fromUtc, $toUtc]
        );
        $cogsByDay = [];
        foreach (DB::all(
            "SELECT DATE(DATE_ADD(s.created_at, INTERVAL $off SECOND)) AS d, SUM(si.quantity * si.unit_cost) AS cogs
               FROM sale_items si JOIN sales s ON s.id = si.sale_id
              WHERE s.status = 'completed' AND s.created_at >= ? AND s.created_at < ?
              GROUP BY d",
            [$fromUtc, $toUtc]
        ) as $c) {
            $cogsByDay[$c['d']] = Money::toCents((string) $c['cogs']);
        }

        $buckets = [];
        foreach ($daily as $d) {
            $date = new DateTimeImmutable($d['d']);
            [$key, $label] = match ($req['group']) {
                'week' => [
                    $date->modify('monday this week')->format('Y-m-d'),
                    'Week of ' . $date->modify('monday this week')->format('M j, Y'),
                ],
                'month' => [$date->format('Y-m'), $date->format('F Y')],
                default => [$d['d'], $date->format('D, M j, Y')],
            };
            $b = $buckets[$key] ?? ['period' => $label, 'txn' => 0, 'gross' => 0, 'disc' => 0, 'net' => 0, 'cogs' => 0, 'voids' => 0];
            $b['txn'] += (int) $d['txn_count'];
            $b['gross'] += Money::toCents((string) $d['gross']);
            $b['disc'] += Money::toCents((string) $d['discounts']);
            $b['net'] += Money::toCents((string) $d['net']);
            $b['cogs'] += $cogsByDay[$d['d']] ?? 0;
            $b['voids'] += (int) $d['void_count'];
            $buckets[$key] = $b;
        }
        ksort($buckets);
        $r['columns'] = [
            ['period', 'Period', 'text'],
            ['txn', 'Transactions', 'int'],
            ['gross', 'Gross sales', 'money'],
            ['disc', 'Discounts', 'money'],
            ['net', 'Net sales', 'money'],
            ['cogs', 'Cost of goods', 'money'],
            ['profit', 'Gross profit', 'money'],
            ['voids', 'Voids', 'int'],
        ];
        foreach ($buckets as $b) {
            $r['rows'][] = [
                'period' => $b['period'],
                'txn' => $b['txn'],
                'gross' => Money::toDecimal($b['gross']),
                'disc' => Money::toDecimal($b['disc']),
                'net' => Money::toDecimal($b['net']),
                'cogs' => Money::toDecimal($b['cogs']),
                'profit' => Money::toDecimal($b['net'] - $b['cogs']),
                'voids' => $b['voids'],
            ];
        }
        $r['summary'] = self::summaryRows(self::summary($fromUtc, $toUtc));
        $r['notes'][] = 'Net sales = gross sales − discounts. Gross profit = net sales − cost of goods (cost captured at time of sale). Voided sales are excluded.';
        return $r;
    }

    private static function transactionsReport(array $r, string $fromUtc, string $toUtc): array
    {
        $r['columns'] = [
            ['transaction_no', 'Transaction #', 'text'],
            ['created_at', 'Date & time', 'datetime'],
            ['cashier', 'Cashier', 'text'],
            ['item_count', 'Items', 'int'],
            ['subtotal', 'Subtotal', 'money'],
            ['discount_amount', 'Discount', 'money'],
            ['total', 'Total', 'money'],
            ['payment_method', 'Payment', 'text'],
            ['status', 'Status', 'text'],
        ];
        $r['rows'] = DB::all(
            'SELECT s.transaction_no, s.created_at, u.username AS cashier, s.item_count, s.subtotal, s.discount_amount,
                    s.total, s.payment_method, s.status
               FROM sales s JOIN users u ON u.id = s.user_id
              WHERE s.created_at >= ? AND s.created_at < ?
              ORDER BY s.created_at, s.id LIMIT 20000',
            [$fromUtc, $toUtc]
        );
        $r['summary'] = self::summaryRows(self::summary($fromUtc, $toUtc));
        return $r;
    }

    private static function productSalesReport(array $r, string $fromUtc, string $toUtc): array
    {
        $r['columns'] = [
            ['sku', 'SKU', 'text'],
            ['product_name', 'Product', 'text'],
            ['qty', 'Qty sold', 'int'],
            ['revenue', 'Sales (before discounts)', 'money'],
            ['cost', 'Cost of goods', 'money'],
        ];
        $r['rows'] = DB::all(
            "SELECT si.sku, MAX(si.product_name) AS product_name, SUM(si.quantity) AS qty,
                    SUM(si.line_total) AS revenue, SUM(si.quantity * si.unit_cost) AS cost
               FROM sale_items si JOIN sales s ON s.id = si.sale_id
              WHERE s.status = 'completed' AND s.created_at >= ? AND s.created_at < ?
              GROUP BY si.product_id, si.sku ORDER BY qty DESC, revenue DESC LIMIT 5000",
            [$fromUtc, $toUtc]
        );
        $r['notes'][] = 'Product sales are line totals before sale-level discounts. Voided sales are excluded.';
        return $r;
    }

    private static function inventoryReport(array $r): array
    {
        $t = ProductService::thresholdSql();
        $r['columns'] = [
            ['sku', 'SKU', 'text'],
            ['name', 'Product', 'text'],
            ['category', 'Category', 'text'],
            ['stock_qty', 'Stock', 'int'],
            ['unit', 'Unit', 'text'],
            ['cost_price', 'Unit cost', 'money'],
            ['selling_price', 'Unit price', 'money'],
            ['cost_value', 'Value at cost', 'money'],
            ['retail_value', 'Value at price', 'money'],
            ['stock_status', 'Status', 'text'],
        ];
        $rows = DB::all(
            "SELECT p.sku, p.name, COALESCE(c.name, '') AS category, p.stock_qty, p.unit, p.cost_price, p.selling_price,
                    GREATEST(p.stock_qty, 0) * p.cost_price AS cost_value,
                    GREATEST(p.stock_qty, 0) * p.selling_price AS retail_value,
                    CASE WHEN p.stock_qty <= 0 THEN 'Out of stock' WHEN p.stock_qty <= $t THEN 'Low stock' ELSE 'In stock' END AS stock_status
               FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.is_active = 1 ORDER BY p.name LIMIT 20000"
        );
        $cost = 0;
        $retail = 0;
        $units = 0;
        foreach ($rows as &$row) {
            $row['cost_value'] = Money::toDecimal(Money::toCents((string) $row['cost_value']));
            $row['retail_value'] = Money::toDecimal(Money::toCents((string) $row['retail_value']));
            $cost += Money::toCents($row['cost_value']);
            $retail += Money::toCents($row['retail_value']);
            $units += max(0, (int) $row['stock_qty']);
        }
        unset($row);
        $r['rows'] = $rows;
        $r['summary'] = [
            ['Active products', count($rows), 'int'],
            ['Units in stock', $units, 'int'],
            ['Inventory value at cost', Money::toDecimal($cost), 'money'],
            ['Inventory value at selling price', Money::toDecimal($retail), 'money'],
        ];
        $r['notes'][] = 'Valuation uses current cost prices of active products. Archived products are excluded.';
        return $r;
    }

    private static function stockReport(array $r, string $which): array
    {
        $t = ProductService::thresholdSql();
        $cond = $which === 'low' ? "p.stock_qty > 0 AND p.stock_qty <= $t" : 'p.stock_qty <= 0';
        $r['columns'] = [
            ['sku', 'SKU', 'text'],
            ['name', 'Product', 'text'],
            ['category', 'Category', 'text'],
            ['stock_qty', 'Stock', 'int'],
            ['threshold', 'Low-stock threshold', 'int'],
            ['unit', 'Unit', 'text'],
            ['updated_at', 'Last updated', 'datetime'],
        ];
        $r['rows'] = DB::all(
            "SELECT p.sku, p.name, COALESCE(c.name, '') AS category, p.stock_qty, $t AS threshold, p.unit, p.updated_at
               FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.is_active = 1 AND $cond ORDER BY p.stock_qty ASC, p.name LIMIT 20000"
        );
        $r['summary'] = [['Products', count($r['rows']), 'int']];
        return $r;
    }

    private static function movementsReport(array $r, string $fromUtc, string $toUtc): array
    {
        $r['columns'] = [
            ['created_at', 'Date & time', 'datetime'],
            ['sku', 'SKU', 'text'],
            ['product_name', 'Product', 'text'],
            ['movement_type', 'Type', 'text'],
            ['qty_before', 'Before', 'int'],
            ['qty_change', 'Change', 'int'],
            ['qty_after', 'After', 'int'],
            ['reason', 'Reason / reference', 'text'],
            ['username', 'User', 'text'],
        ];
        $rows = InventoryService::movements(['from_utc' => $fromUtc, 'to_utc' => $toUtc], 0)['rows'];
        foreach ($rows as &$row) {
            if ($row['transaction_no']) {
                $row['reason'] = trim($row['reason'] . ' ' . $row['transaction_no']);
            }
        }
        unset($row);
        $r['rows'] = $rows;
        $r['summary'] = [['Movements', count($rows), 'int']];
        return $r;
    }

    /** Dashboard figures. */
    public static function dashboard(string $from, string $to): array
    {
        $today = Clock::todayLocal();
        [$tFrom, $tTo] = Clock::localRangeToUtc($today, $today);
        [$rFrom, $rTo] = Clock::localRangeToUtc($from, $to);
        $t = ProductService::thresholdSql();
        $counts = DB::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(p.stock_qty > 0 AND p.stock_qty <= $t), 0) AS low,
                    COALESCE(SUM(p.stock_qty <= 0), 0) AS out_of_stock
               FROM products p WHERE p.is_active = 1"
        );
        return [
            'today' => self::summary($tFrom, $tTo),
            'range' => self::summary($rFrom, $rTo),
            'products_total' => (int) $counts['total'],
            'low_stock' => (int) $counts['low'],
            'out_of_stock' => (int) $counts['out_of_stock'],
            'recent' => DB::all(
                'SELECT s.id, s.transaction_no, s.created_at, s.item_count, s.total, s.status
                   FROM sales s ORDER BY s.created_at DESC, s.id DESC LIMIT 8'
            ),
            'low_items' => DB::all(
                "SELECT p.id, p.sku, p.name, p.stock_qty, p.unit, $t AS threshold
                   FROM products p WHERE p.is_active = 1 AND p.stock_qty <= $t
                  ORDER BY p.stock_qty ASC, p.name LIMIT 8"
            ),
        ];
    }
}
