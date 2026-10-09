<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Http;
use App\Core\ValidationException;
use App\Services\ProductImport;

/** Product CSV import: upload → preview with row-level validation → confirm → summary. */
final class ImportController extends Controller
{
    public function template(): void
    {
        $example = Http::query('example') === '1';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="motosupply-products-' . ($example ? 'template-with-example' : 'template') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo ProductImport::template($example);
    }

    public function index(): void
    {
        $token = Http::query('token');
        $mode = Http::query('mode') === 'update' ? 'update' : 'create';
        $error = null;
        if (Http::isPost()) {
            try {
                $token = ProductImport::stash($_FILES['file'] ?? null);
                Http::redirect(url('products.import', ['token' => $token, 'mode' => $mode]));
            } catch (ValidationException $e) {
                $error = $e->getMessage();
                $token = '';
            }
        }
        $analysis = null;
        if ($token !== '') {
            $path = ProductImport::path($token);
            if ($path === null) {
                $error = 'The uploaded file has expired. Please upload it again.';
            } else {
                try {
                    $analysis = ProductImport::analyse(ProductImport::parse($path), $mode);
                } catch (ValidationException $e) {
                    $error = $e->getMessage();
                    ProductImport::discard($token);
                    $token = '';
                }
            }
        }
        $this->view('pages/import', [
            'title' => 'Import products',
            'nav' => 'products',
            'token' => $token,
            'mode' => $mode,
            'analysis' => $analysis,
            'error' => $error,
            'result' => $_SESSION['_import_result'] ?? null,
        ]);
        unset($_SESSION['_import_result']);
    }

    public function confirm(): void
    {
        $token = Http::post('token');
        $mode = Http::post('mode') === 'update' ? 'update' : 'create';
        $path = ProductImport::path($token);
        if ($path === null) {
            Http::flash('error', 'The uploaded file has expired. Please upload it again.');
            Http::redirect(url('products.import'));
        }
        try {
            $analysis = ProductImport::analyse(ProductImport::parse($path), $mode);
            $c = $analysis['counts'];
            // The preview the user confirmed must still be accurate (data may have changed since).
            if (Http::post('expect') !== "{$c['create']}:{$c['update']}:{$c['skip']}:{$c['error']}") {
                Http::flash('error', 'The data changed since the preview (for example another user added a product). Review the updated preview and confirm again.');
                Http::redirect(url('products.import', ['token' => $token, 'mode' => $mode]));
            }
            if ($c['update'] > 0 && Http::post('confirm_update') !== '1') {
                Http::flash('error', 'Tick the box to confirm that ' . $c['update'] . ' existing product(s) will be changed.');
                Http::redirect(url('products.import', ['token' => $token, 'mode' => $mode]));
            }
            if ($c['create'] + $c['update'] === 0) {
                Http::flash('error', 'There are no valid rows to import.');
                Http::redirect(url('products.import', ['token' => $token, 'mode' => $mode]));
            }
            $done = ProductImport::apply($analysis, Auth::id());
            $failedLines = array_map(static fn ($r) => ['line' => $r['line'], 'sku' => $r['input']['sku'] ?? '', 'reason' => implode('; ', $r['errors'])],
                array_values(array_filter($analysis['rows'], static fn ($r) => in_array($r['action'], ['skip', 'error'], true))));
            $summary = ['mode' => $mode, 'created' => $done['created'], 'updated' => $done['updated'], 'skipped' => $c['skip'], 'failed' => $c['error'], 'not_imported' => array_slice($failedLines, 0, 200)];
            Audit::log('products.import', 'import', $token, ['mode' => $mode, 'created' => $done['created'], 'updated' => $done['updated'], 'skipped' => $c['skip'], 'failed' => $c['error']]);
            $_SESSION['_import_result'] = $summary;
            ProductImport::discard($token);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Product import failed', $e);
            Audit::log('products.import', 'import', $token, ['mode' => $mode, 'error' => $e->getMessage()], 'failure');
            Http::flash('error', 'The import stopped and NOTHING was changed (all rows were rolled back): '
                . ($e instanceof ValidationException ? $e->getMessage() : 'unexpected database error') . '. Fix the file and try again.');
            Http::redirect(url('products.import', ['token' => $token, 'mode' => $mode]));
        }
        Http::redirect(url('products.import'));
    }
}
