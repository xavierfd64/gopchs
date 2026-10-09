<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\ValidationException;

/**
 * Product CSV import.
 *
 * Columns (header row required, names case-insensitive, order free):
 *   name*, sku*, barcode, category, description, cost_price, selling_price*, stock_qty,
 *   low_stock_threshold, unit
 *
 * Modes:
 *   create  — create new products only; rows whose SKU already exists are skipped.
 *   update  — also update existing products (matched by SKU): name, barcode, category,
 *             description, unit, prices, threshold. STOCK OF EXISTING PRODUCTS IS NEVER CHANGED
 *             by an import (use Adjust stock); stock_qty is the opening stock of NEW products.
 *
 * The whole import runs in one database transaction: either every valid row is written or none.
 * Rows whose SKU starts with "EXAMPLE-" (the template's sample row) are always skipped.
 */
final class ProductImport
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const COLUMNS = [
        'name' => 'Product name (required)',
        'sku' => 'SKU (required, unique)',
        'barcode' => 'Barcode (optional, unique)',
        'category' => 'Category',
        'description' => 'Description',
        'cost_price' => 'Cost price',
        'selling_price' => 'Selling price (required)',
        'stock_qty' => 'Opening stock (new products only)',
        'low_stock_threshold' => 'Low-stock threshold (blank = default)',
        'unit' => 'Unit (pc, set, bottle…)',
    ];
    private const ALIASES = [
        'product name' => 'name', 'product' => 'name', 'stock' => 'stock_qty', 'quantity' => 'stock_qty',
        'current stock quantity' => 'stock_qty', 'cost' => 'cost_price', 'price' => 'selling_price',
        'selling price' => 'selling_price', 'cost price' => 'cost_price', 'low stock threshold' => 'low_stock_threshold',
        'threshold' => 'low_stock_threshold', 'reorder' => 'low_stock_threshold',
    ];

    public static function template(bool $withExample): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_keys(self::COLUMNS), ',', '"', '');
        if ($withExample) {
            fputcsv($fh, ['EXAMPLE ROW - delete before importing (always skipped)', 'EXAMPLE-SKU-001', '4800000000000', 'Engine Oil',
                'Sample description', '350.00', '450.00', '24', '10', 'bottle'], ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** Store an uploaded CSV for the preview/confirm steps. Returns a token. */
    public static function stash(?array $file): string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['file' => 'Choose a CSV file.']);
        }
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new ValidationException(['file' => 'The upload failed. Try again with a smaller file.']);
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['file' => 'The file is larger than 2 MB. Split it into smaller files.']);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4096);
        if (!in_array($ext, ['csv', 'txt'], true) || str_contains($head, "\0") || str_starts_with($head, "PK\x03\x04")) {
            throw new ValidationException(['file' => 'Upload a .csv file (in Excel: File → Save As → CSV UTF-8). Excel .xlsx files cannot be imported directly.']);
        }
        $dir = MOTO_ROOT . '/storage/imports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        foreach (glob($dir . '/*.csv') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        if (!move_uploaded_file((string) $file['tmp_name'], "$dir/$token.csv")) {
            throw new ValidationException(['file' => 'The file could not be stored. Check Settings → System Check.']);
        }
        return $token;
    }

    public static function path(string $token): ?string
    {
        $p = MOTO_ROOT . '/storage/imports/' . $token . '.csv';
        return preg_match('/^[a-f0-9]{32}$/', $token) && is_file($p) ? $p : null;
    }

    public static function discard(string $token): void
    {
        if (($p = self::path($token)) !== null) {
            @unlink($p);
        }
    }

    /** Parse the CSV into associative rows keyed by column name. */
    public static function parse(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // older Excel "CSV"
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh, null, $delim, '"', '');
        if (!is_array($header)) {
            fclose($fh);
            throw new ValidationException(['file' => 'The file is empty.']);
        }
        $cols = [];
        foreach ($header as $i => $h) {
            $k = strtolower(trim((string) $h));
            $k = self::ALIASES[$k] ?? str_replace(' ', '_', $k);
            if (isset(self::COLUMNS[$k])) {
                $cols[$i] = $k;
            }
        }
        $missing = array_diff(['name', 'sku', 'selling_price'], $cols);
        if ($missing) {
            fclose($fh);
            throw new ValidationException(['file' => 'Missing required column(s): ' . implode(', ', $missing) . '. Download the template to see the expected headers.']);
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($fh, null, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($fh);
                throw new ValidationException(['file' => 'The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.']);
            }
            $row = ['_line' => $line];
            foreach ($cols as $i => $k) {
                $v = trim((string) ($r[$i] ?? ''));
                // Undo the apostrophe our CSV exports add to neutralise spreadsheet formulas.
                if (preg_match("/^'[=+\\-@]/", $v)) {
                    $v = substr($v, 1);
                }
                $row[$k] = $v;
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Validate every row and decide its action under $mode.
     * @return array{rows:list<array>,counts:array}
     */
    public static function analyse(array $rows, string $mode): array
    {
        $seenSku = [];
        $seenBarcode = [];
        $out = [];
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        foreach ($rows as $r) {
            $res = ['line' => $r['_line'], 'input' => $r, 'action' => 'error', 'errors' => [], 'data' => null, 'id' => null];
            $sku = strtoupper((string) ($r['sku'] ?? ''));
            if (str_starts_with($sku, 'EXAMPLE-')) {
                $res['action'] = 'skip';
                $res['errors'][] = 'Template example row';
            } else {
                $existing = $sku !== '' ? DB::one('SELECT id, barcode FROM products WHERE sku = ?', [$sku]) : null;
                try {
                    $data = ProductService::validate($r + ['stock_qty' => '0'], $existing === null);
                    $res['data'] = $data;
                } catch (ValidationException $e) {
                    $res['errors'] = array_values($e->errors);
                }
                if (isset($seenSku[$sku]) && $sku !== '') {
                    $res['errors'][] = 'Duplicate SKU in this file (also on line ' . $seenSku[$sku] . ')';
                }
                $bc = (string) ($r['barcode'] ?? '');
                if ($bc !== '') {
                    if (isset($seenBarcode[$bc])) {
                        $res['errors'][] = 'Duplicate barcode in this file (also on line ' . $seenBarcode[$bc] . ')';
                    }
                    $owner = DB::value('SELECT id FROM products WHERE barcode = ? AND sku <> ?', [$bc, $sku]);
                    if ($owner !== null) {
                        $res['errors'][] = 'Barcode already belongs to another product';
                    }
                    $seenBarcode[$bc] = $r['_line'];
                }
                if ($sku !== '') {
                    $seenSku[$sku] = $seenSku[$sku] ?? $r['_line'];
                }
                if ($res['errors'] === []) {
                    if ($existing === null) {
                        $res['action'] = 'create';
                    } elseif ($mode === 'update') {
                        $res['action'] = 'update';
                        $res['id'] = (int) $existing['id'];
                    } else {
                        $res['action'] = 'skip';
                        $res['errors'][] = 'SKU already exists (skipped in "create new only" mode)';
                    }
                }
            }
            $counts[$res['action']]++;
            $out[] = $res;
        }
        return ['rows' => $out, 'counts' => $counts];
    }

    /**
     * Write all "create" and "update" rows in ONE transaction. If anything fails, nothing is
     * written and the error is reported. Returns the counts actually applied.
     */
    public static function apply(array $analysis, int $userId): array
    {
        return DB::transaction(static function () use ($analysis, $userId): array {
            $done = ['created' => 0, 'updated' => 0];
            foreach ($analysis['rows'] as $r) {
                if ($r['action'] === 'create') {
                    ProductService::create($r['data'], $userId);
                    $done['created']++;
                } elseif ($r['action'] === 'update') {
                    ProductService::update((int) $r['id'], $r['data']);
                    $done['updated']++;
                }
            }
            return $done;
        });
    }
}
