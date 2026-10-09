<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Money;
use App\Core\Settings;
use App\Lib\SimplePdf;

/** Renders report definitions (see ReportService) as CSV or PDF. */
final class ReportExporter
{
    /** Display formatting shared by HTML and PDF. */
    public static function display(mixed $value, string $type, ?string $symbol = null): string
    {
        if ($value === null || $value === '') {
            return $type === 'money' ? Money::format(0, $symbol) : '';
        }
        return match ($type) {
            'money' => Money::format(Money::toCents((string) $value), $symbol),
            'int' => number_format((int) $value),
            'datetime' => Clock::toLocal((string) $value, 'Y-m-d g:i A'),
            default => in_array($value, ['completed', 'voided', 'cash', 'sale', 'void', 'adjustment', 'initial'], true)
                ? ucfirst((string) $value)
                : (string) $value,
        };
    }

    /**
     * Neutralize spreadsheet formula injection: text cells starting with = + - @ tab or CR
     * are prefixed with an apostrophe. Numeric cells are written as plain numbers.
     */
    public static function csvCell(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }
        $s = match ($type) {
            'money' => Money::toDecimal(Money::toCents((string) $value)),
            'int' => (string) (int) $value,
            'datetime' => Clock::toLocal((string) $value, 'Y-m-d H:i:s'),
            default => (string) $value,
        };
        if ($type !== 'money' && $type !== 'int' && $s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
            $s = "'" . $s;
        }
        return $s;
    }

    public static function filename(array $report, array $req, string $ext): string
    {
        $base = 'motosupply-' . str_replace('_', '-', $report['type']);
        if (in_array($report['type'], ReportService::DATED, true)) {
            $base .= '-' . $req['from'] . '_to_' . $req['to'];
        } else {
            $base .= '-' . Clock::nowLocal()->format('Y-m-d');
        }
        return $base . '.' . $ext;
    }

    /** Write CSV to a stream (php://output in production). */
    public static function csv(array $report, $out): void
    {
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps detect the encoding
        $put = static function (array $fields) use ($out): void {
            fputcsv($out, $fields, ',', '"', '');
        };
        $currency = Settings::get('currency_code', 'PHP');
        $put([self::csvCell(Settings::get('shop_name'), 'text')]);
        $put([self::csvCell($report['title'], 'text')]);
        $put([self::csvCell($report['range'], 'text')]);
        $put(['Generated ' . Clock::nowLocal()->format('Y-m-d H:i:s T')]);
        $put([]);
        $put(array_map(
            static fn ($c) => self::csvCell($c[1] . ($c[2] === 'money' ? " ($currency)" : ''), 'text'),
            $report['columns']
        ));
        foreach ($report['rows'] as $row) {
            $put(array_map(static fn ($c) => self::csvCell($row[$c[0]] ?? null, $c[2]), $report['columns']));
        }
        if ($report['rows'] === []) {
            $put(['No records for the selected period.']);
        }
        if ($report['summary']) {
            $put([]);
            $put(['Summary']);
            foreach ($report['summary'] as [$label, $value, $type]) {
                $put([self::csvCell($label . ($type === 'money' ? " ($currency)" : ''), 'text'), self::csvCell($value, $type)]);
            }
        }
        foreach ($report['notes'] as $note) {
            $put([self::csvCell($note, 'text')]);
        }
    }

    public static function pdf(array $report): string
    {
        $landscape = count($report['columns']) > 5;
        $pdf = new SimplePdf($landscape);
        $margin = 36.0;
        $usable = $pdf->width - 2 * $margin;
        $fs = 8.5;
        $rowH = 15.0;
        $currency = Settings::get('currency_code', 'PHP');
        $symbol = $currency . ' '; // the peso sign is not available in the built-in PDF fonts

        // Column widths: proportional to content, capped, then scaled to the usable width.
        $widths = [];
        $sample = array_slice($report['rows'], 0, 300);
        foreach ($report['columns'] as $i => [$key, $label, $type]) {
            $w = $pdf->textWidth($label, $fs, true);
            foreach ($sample as $row) {
                $w = max($w, $pdf->textWidth(self::display($row[$key] ?? null, $type, $symbol), $fs));
            }
            $widths[$i] = min($w + 10, 220);
        }
        $scale = $usable / max(1, array_sum($widths));
        $widths = array_map(static fn ($w) => $w * $scale, $widths);

        $shop = Settings::get('shop_name', 'MotoSupply Shop');
        $generated = 'Generated ' . Clock::nowLocal()->format('M j, Y g:i A');
        $y = 0.0;

        $header = function () use ($pdf, $report, $margin, $shop, $generated, &$y): void {
            $pdf->addPage();
            $pdf->text($margin, $margin + 10, $shop, 14, true);
            $pdf->text($pdf->width - $margin, $margin + 10, $generated, 8, false, 'R', 0.35);
            $pdf->text($margin, $margin + 28, $report['title'], 11, true);
            $pdf->text($margin, $margin + 42, $report['range'], 9, false, 'L', 0.3);
            $pdf->line($margin, $margin + 50, $pdf->width - $margin, $margin + 50, 0.8, 0.2);
            $y = $margin + 62;
        };
        $tableHeader = function () use ($pdf, $report, $widths, $margin, $fs, $rowH, &$y): void {
            $pdf->fillRect($margin, $y, array_sum($widths), $rowH, 0.9);
            $x = $margin;
            foreach ($report['columns'] as $i => [, $label, $type]) {
                $right = in_array($type, ['money', 'int'], true);
                $text = $pdf->fit($label, $widths[$i] - 6, $fs, true);
                $pdf->text($right ? $x + $widths[$i] - 3 : $x + 3, $y + 10.5, $text, $fs, true, $right ? 'R' : 'L');
                $x += $widths[$i];
            }
            $y += $rowH;
        };
        $bottom = $pdf->height - $margin - 20;

        $header();
        $tableHeader();
        if ($report['rows'] === []) {
            $pdf->text($margin + 3, $y + 14, 'No records for the selected period.', 9, false, 'L', 0.3);
            $y += $rowH + 6;
        }
        foreach ($report['rows'] as $n => $row) {
            if ($y + $rowH > $bottom) {
                $header();
                $tableHeader();
            }
            if ($n % 2 === 1) {
                $pdf->fillRect($margin, $y, array_sum($widths), $rowH, 0.97);
            }
            $x = $margin;
            foreach ($report['columns'] as $i => [$key, , $type]) {
                $right = in_array($type, ['money', 'int'], true);
                $text = $pdf->fit(self::display($row[$key] ?? null, $type, $symbol), $widths[$i] - 6, $fs);
                $pdf->text($right ? $x + $widths[$i] - 3 : $x + 3, $y + 10.5, $text, $fs, false, $right ? 'R' : 'L');
                $x += $widths[$i];
            }
            $y += $rowH;
        }
        $pdf->line($margin, $y, $margin + array_sum($widths), $y, 0.5, 0.5);

        if ($report['summary']) {
            $needed = 30 + count($report['summary']) * 14;
            if ($y + $needed > $bottom) {
                $header();
            }
            $y += 22;
            $pdf->text($margin, $y, 'Summary', 10, true);
            $y += 6;
            foreach ($report['summary'] as [$label, $value, $type]) {
                $y += 14;
                $pdf->text($margin, $y, $label, 9);
                $pdf->text($margin + 260, $y, self::display($value, $type, $symbol), 9, true, 'R');
            }
        }
        foreach ($report['notes'] as $note) {
            if ($y + 30 > $bottom) {
                $header();
            }
            $y += 20;
            $pdf->text($margin, $y, $pdf->fit($note, $usable, 7.5), 7.5, false, 'L', 0.35);
        }
        $total = $pdf->pageCount();
        for ($p = 0; $p < $total; $p++) {
            $pdf->setPage($p);
            $pdf->text($margin, $pdf->height - $margin + 6, 'Amounts in ' . $currency . '.', 7, false, 'L', 0.45);
            $pdf->text($pdf->width - $margin, $pdf->height - $margin + 6, 'Page ' . ($p + 1) . ' of ' . $total, 7, false, 'R', 0.45);
        }
        return $pdf->output();
    }
}
