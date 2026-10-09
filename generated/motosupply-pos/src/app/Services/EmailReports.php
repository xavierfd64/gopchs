<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Money;
use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * End-of-day email report.
 *
 * Reporting rules:
 *  - The report covers ONE complete calendar day, 00:00:00–23:59:59 in the report timezone
 *    (the email timezone setting, or the shop timezone). It is sent on the following day at the
 *    configured delivery time or later (depending on the trigger, see README).
 *  - Gross sales = sum of line totals of completed sales; Net sales = gross − discounts.
 *    Voided sales are excluded from both and listed separately. Rejected sales are never saved.
 *  - Profit is not included (it is not "sales"); see the in-app Reports for gross profit.
 *  - Low/out-of-stock lists show stock at the time the report is generated (stock is not
 *    stored per day), which is stated in the email.
 *  - One delivery record per report date (unique key) prevents duplicate emails.
 */
final class EmailReports
{
    public const SECTIONS = [
        'summary' => 'Sales summary',
        'low_stock' => 'Low-stock products',
        'out_of_stock' => 'Out-of-stock products',
        'top_products' => 'Top products sold',
    ];
    public const MAX_ATTEMPTS = 3;

    public static function timezone(): string
    {
        $tz = Settings::get('email_timezone');
        return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : Settings::get('timezone', 'Asia/Manila');
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Settings::get('email_recipients'))),
            static fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** The report date due now, or null if nothing is due yet. */
    public static function dueDate(?DateTimeImmutable $now = null): ?string
    {
        $tz = new DateTimeZone(self::timezone());
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', Settings::get('email_time', '06:00')) + [0, 0]);
        $sendAt = $now->setTime($h, $m);
        return $now >= $sendAt ? $now->modify('-1 day')->format('Y-m-d') : null;
    }

    /** Build the report for a local date. All figures come from one repeatable-read snapshot. */
    public static function build(string $date): array
    {
        return Clock::withTimezone(self::timezone(), static function () use ($date): array {
            $pdo = DB::pdo();
            $own = !$pdo->inTransaction();
            if ($own) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION READ ONLY');
            }
            try {
                [$from, $to] = Clock::localRangeToUtc($date, $date);
                $sum = ReportService::summary($from, $to);
                $t = ProductService::thresholdSql();
                $low = DB::all("SELECT p.sku, p.name, p.stock_qty, $t AS threshold FROM products p
                                 WHERE p.is_active = 1 AND p.stock_qty > 0 AND p.stock_qty <= $t ORDER BY p.stock_qty, p.name LIMIT 200");
                $out = DB::all('SELECT p.sku, p.name FROM products p WHERE p.is_active = 1 AND p.stock_qty <= 0 ORDER BY p.name LIMIT 200');
                $top = DB::all(
                    "SELECT si.sku, MAX(si.product_name) AS name, SUM(si.quantity) AS qty, SUM(si.line_total) AS sales
                       FROM sale_items si JOIN sales s ON s.id = si.sale_id
                      WHERE s.status = 'completed' AND s.created_at >= ? AND s.created_at < ?
                      GROUP BY si.product_id, si.sku ORDER BY qty DESC LIMIT 10", [$from, $to]);
                $req = ['type' => 'transactions', 'group' => 'day', 'from' => $date, 'to' => $date, 'preset' => ''];
                $transactions = ReportService::build($req);
                $salesReport = ReportService::build(['type' => 'sales'] + $req);
            } finally {
                if ($own) {
                    $pdo->exec('COMMIT');
                }
            }
            return compact('date', 'sum', 'low', 'out', 'top', 'transactions', 'salesReport') + ['timezone' => self::timezone(), 'generated_at' => Clock::nowUtc()];
        });
    }

    /** @return array{subject:string,text:string,html:string,attachments:list<array>} */
    public static function compose(array $r, bool $test = false): array
    {
        $shop = Settings::get('shop_name');
        $sections = array_filter(explode(',', Settings::get('email_sections')));
        $s = $r['sum'];
        $label = (new DateTimeImmutable($r['date']))->format('D, M j, Y');
        $m = static fn (int $c) => Money::format($c);
        $lines = [];
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1b1c20">'
            . '<h2 style="margin:0 0 4px">' . e($shop) . ' — end-of-day report</h2>'
            . '<p style="margin:0 0 16px;color:#555">' . e($label) . ' (' . e($r['timezone']) . ', 00:00–23:59)' . ($test ? ' — <b>TEST EMAIL</b>' : '') . '</p>';
        $lines[] = "$shop — end-of-day report for $label ({$r['timezone']})" . ($test ? ' [TEST EMAIL]' : '');
        $table = static function (string $title, array $rows) use (&$html, &$lines): void {
            $html .= '<h3 style="margin:18px 0 6px">' . e($title) . '</h3><table cellpadding="6" style="border-collapse:collapse;border:1px solid #ddd">';
            $lines[] = '';
            $lines[] = strtoupper($title);
            foreach ($rows as $row) {
                $html .= '<tr>' . implode('', array_map(static fn ($c) => '<td style="border:1px solid #ddd">' . e((string) $c) . '</td>', $row)) . '</tr>';
                $lines[] = '  ' . implode(' | ', $row);
            }
            if ($rows === []) {
                $html .= '<tr><td style="border:1px solid #ddd">None</td></tr>';
                $lines[] = '  None';
            }
            $html .= '</table>';
        };
        if (in_array('summary', $sections, true)) {
            $table('Sales summary', [
                ['Completed transactions', number_format($s['txn_count'])],
                ['Units sold', number_format($s['items'])],
                ['Gross sales', $m($s['gross'])],
                ['Discounts', $m($s['discounts'])],
                ['Net sales (gross − discounts)', $m($s['net'])],
                ['Voided transactions (excluded)', number_format($s['void_count']) . ' / ' . $m($s['void_total'])],
                ['Products at or below low-stock level', number_format(count($r['low']) + count($r['out']))],
            ]);
        }
        if (in_array('low_stock', $sections, true)) {
            $table('Low-stock products', array_map(static fn ($p) => [$p['sku'], $p['name'], $p['stock_qty'] . ' left (threshold ' . $p['threshold'] . ')'], $r['low']));
        }
        if (in_array('out_of_stock', $sections, true)) {
            $table('Out-of-stock products', array_map(static fn ($p) => [$p['sku'], $p['name']], $r['out']));
        }
        if (in_array('top_products', $sections, true)) {
            $table('Top products sold', array_map(static fn ($p) => [$p['sku'], $p['name'], $p['qty'] . ' sold', Money::format(Money::toCents((string) $p['sales']))], $r['top']));
        }
        $note = 'Net sales = gross sales − discounts; voided sales are excluded. Figures are revenue, not profit. '
            . 'Stock lists show stock when this report was generated (' . Clock::toLocal($r['generated_at'] ?? Clock::nowUtc()) . '), not at the end of the day.';
        $html .= '<p style="color:#777;font-size:12px;margin-top:18px">' . e($note) . '</p></div>';
        $lines[] = '';
        $lines[] = $note;

        $attachments = [];
        if (Settings::get('email_attach_pdf') === '1') {
            $attachments[] = ['name' => "sales-summary-{$r['date']}.pdf", 'type' => 'application/pdf', 'data' => ReportExporter::pdf($r['salesReport'])];
            $attachments[] = ['name' => "transactions-{$r['date']}.pdf", 'type' => 'application/pdf', 'data' => ReportExporter::pdf($r['transactions'])];
        }
        if (Settings::get('email_attach_csv') === '1') {
            $fh = fopen('php://temp', 'w+');
            ReportExporter::csv($r['transactions'], $fh);
            rewind($fh);
            $attachments[] = ['name' => "transactions-{$r['date']}.csv", 'type' => 'text/csv; charset=utf-8', 'data' => (string) stream_get_contents($fh)];
            fclose($fh);
        }
        return [
            'subject' => ($test ? '[TEST] ' : '') . "$shop — sales report for $label: " . $m($s['net']) . ' net, ' . $s['txn_count'] . ' transactions',
            'text' => implode("\n", $lines),
            'html' => $html,
            'attachments' => $attachments,
        ];
    }

    /**
     * Send the report that is due now, exactly once per report date.
     * Returns a short status: disabled | not-due | already-sent | busy | gave-up: … | sent | failed: …
     */
    public static function runScheduled(string $trigger, ?string $forceDate = null): string
    {
        if ($forceDate === null && Settings::get('email_enabled') !== '1') {
            return 'disabled';
        }
        $date = $forceDate ?? self::dueDate();
        if ($date === null) {
            return 'not-due';
        }
        $recipients = self::recipients();
        if ($recipients === []) {
            return 'failed: no recipients configured';
        }
        if (!self::claim($date, $trigger, $recipients)) {
            $st = (string) DB::value('SELECT status FROM email_report_runs WHERE report_date = ?', [$date]);
            return match ($st) {
                'sent' => 'already-sent',
                'failed' => 'gave-up: ' . self::MAX_ATTEMPTS . ' attempts failed for ' . $date,
                default => 'busy',
            };
        }
        try {
            $msg = self::compose(self::build($date));
            Mailer::send($recipients, $msg['subject'], $msg['text'], $msg['html'], $msg['attachments']);
            DB::run("UPDATE email_report_runs SET status = 'sent', error = '', updated_at = ? WHERE report_date = ?", [Clock::nowUtc(), $date]);
            Audit::log('email.report.sent', 'email_report', $date, ['trigger' => $trigger, 'recipients' => count($recipients)], 'success', ['id' => null, 'username' => 'system']);
            return 'sent';
        } catch (\Throwable $e) {
            $err = mb_substr($e instanceof \RuntimeException ? $e->getMessage() : 'Unexpected error while building or sending the report.', 0, 480);
            Logger::error('Daily email report failed for ' . $date, $e);
            DB::run("UPDATE email_report_runs SET status = 'failed', error = ?, updated_at = ? WHERE report_date = ?", [$err, Clock::nowUtc(), $date]);
            Audit::log('email.report.sent', 'email_report', $date, ['trigger' => $trigger, 'error' => $err], 'failure', ['id' => null, 'username' => 'system']);
            return 'failed: ' . $err;
        }
    }

    /**
     * Atomically take responsibility for sending $date. A row that is 'sent' is never resent; a
     * 'failed' row is retried automatically up to MAX_ATTEMPTS times (manual sends may always
     * retry a failed date); a 'sending' row older than 15 minutes
     * (crashed request) may be retried.
     */
    private static function claim(string $date, string $trigger, array $recipients): bool
    {
        $now = Clock::nowUtc();
        $rcpt = mb_substr(implode(', ', $recipients), 0, 500);
        try {
            DB::run(
                "INSERT INTO email_report_runs (report_date, status, attempts, recipients, trigger_src, created_at, updated_at)
                 VALUES (?, 'sending', 1, ?, ?, ?, ?)",
                [$date, $rcpt, $trigger, $now, $now]
            );
            return true;
        } catch (\PDOException $e) {
            if (!DB::isDuplicateKey($e)) {
                throw $e;
            }
        }
        $stale = gmdate('Y-m-d H:i:s', time() - 900);
        return DB::run(
            "UPDATE email_report_runs SET status = 'sending', attempts = attempts + 1, recipients = ?, trigger_src = ?, updated_at = ?
              WHERE report_date = ? AND attempts < ? AND (status = 'failed' OR (status = 'sending' AND updated_at < ?))",
            // A person pressing "Send now" may retry a failed date beyond the automatic limit.
            [$rcpt, $trigger, $now, $date, $trigger === 'manual' ? PHP_INT_MAX : self::MAX_ATTEMPTS, $stale]
        )->rowCount() === 1;
    }

    public static function recentRuns(): array
    {
        return DB::all('SELECT * FROM email_report_runs ORDER BY report_date DESC LIMIT 10');
    }
}
