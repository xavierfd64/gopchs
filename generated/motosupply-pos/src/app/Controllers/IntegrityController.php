<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Http;
use App\Core\ValidationException;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Services\StockIntegrity;
use App\Services\VoidAuthorization;

/** Inventory integrity audit and its auditable corrections. */
final class IntegrityController extends Controller
{
    public function index(): void
    {
        $this->view('pages/integrity', [
            'title' => 'Inventory integrity',
            'nav' => 'products',
            'scan' => StockIntegrity::scan(),
            'error' => $_SESSION['_integrity_error'] ?? null,
        ]);
        unset($_SESSION['_integrity_error']);
    }

    /**
     * kind=count: set a product to its physically counted quantity (stock adjustment).
     * kind=phantom: void a sale whose stock was never deducted, without changing stock;
     *               needs sales.void and a supervisor's void PIN, like any void.
     */
    public function correct(): void
    {
        $kind = Http::post('kind');
        try {
            if ($kind === 'count') {
                $id = $this->idParam('product_id', 'post');
                $r = InventoryService::adjust($id, 'set', Http::post('counted'), 'Integrity correction: ' . Http::post('note'), Auth::id());
                Audit::log('inventory.integrity.count', 'product', $id, $r + ['note' => Http::post('note')]);
                Http::flash('success', "Stock corrected from {$r['qty_before']} to {$r['qty_after']}. The change is recorded in the stock history.");
            } elseif ($kind === 'phantom') {
                if (!Auth::can('sales.void')) {
                    throw new ValidationException(['perm' => 'You need the "Request voids" permission for this correction.']);
                }
                $saleId = $this->idParam('sale_id', 'post');
                $approver = VoidAuthorization::verify(Http::post('approver'), (string) ($_POST['pin'] ?? ''), Http::clientIp());
                $reason = 'Integrity correction (stock was never deducted): ' . Http::post('note');
                SaleService::void($saleId, $reason, Auth::id(), (int) $approver['id'], false);
                Audit::log('sale.void.phantom', 'sale', $saleId, ['approver' => $approver['username'], 'reason' => $reason]);
                Http::flash('success', 'The sale was marked voided without changing stock. It remains in Sales History for auditing.');
            }
        } catch (ValidationException $e) {
            Audit::log('inventory.integrity.' . ($kind === 'phantom' ? 'phantom' : 'count'), $kind, Http::post('sale_id') ?: Http::post('product_id'), ['error' => $e->getMessage()], 'failure');
            $_SESSION['_integrity_error'] = $e->getMessage();
        }
        Http::redirect(url('inventory.integrity'));
    }

    public function enableGuard(): void
    {
        try {
            StockIntegrity::enableGuard();
            Audit::log('inventory.integrity.guard', 'products', 'stock_qty');
            Http::flash('success', 'The database now refuses negative stock.');
        } catch (ValidationException $e) {
            $_SESSION['_integrity_error'] = $e->getMessage();
        }
        Http::redirect(url('inventory.integrity'));
    }
}
