<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Http;
use App\Core\ValidationException;
use App\Services\SaleService;

final class SalesController extends Controller
{
    public function index(): void
    {
        $from = Http::query('from');
        $to = Http::query('to');
        $f = [
            'q' => mb_substr(Http::query('q'), 0, 64),
            'status' => Http::query('status'),
            'page' => $this->idParam('page') ?: 1,
            'from' => Clock::isDate($from) ? $from : '',
            'to' => Clock::isDate($to) ? $to : '',
        ];
        if ($f['from'] !== '' || $f['to'] !== '') {
            $f['from'] = $f['from'] ?: $f['to'];
            $f['to'] = $f['to'] ?: $f['from'];
            if ($f['from'] > $f['to']) {
                [$f['from'], $f['to']] = [$f['to'], $f['from']];
            }
            [$f['from_utc'], $f['to_utc']] = Clock::localRangeToUtc($f['from'], $f['to']);
        }
        $this->view('pages/sales', [
            'title' => 'Sales History',
            'nav' => 'sales',
            'f' => $f,
            'list' => SaleService::list($f),
        ]);
    }

    public function show(): void
    {
        $sale = SaleService::find($this->idParam());
        if ($sale === null) {
            $this->notFound('sale');
        }
        $this->view('pages/sale_view', [
            'title' => 'Sale ' . $sale['transaction_no'],
            'nav' => 'sales',
            'sale' => $sale,
            'voidError' => $_SESSION['_void_error'] ?? null,
        ]);
        unset($_SESSION['_void_error']);
    }

    /** Voiding requires the administrator to re-enter their password and give a reason. */
    public function void(): void
    {
        $id = $this->idParam('id', 'post');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!Auth::verifyCurrentPassword($password)) {
            $_SESSION['_void_error'] = 'Password is incorrect. The sale was not voided.';
            Http::redirect(url('sales.view', ['id' => $id]));
        }
        try {
            SaleService::void($id, Http::post('reason'), Auth::id());
            Http::flash('success', 'The sale was voided and its stock was returned to inventory.');
        } catch (ValidationException $e) {
            $_SESSION['_void_error'] = $e->getMessage();
        }
        Http::redirect(url('sales.view', ['id' => $id]));
    }
}
