<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Http;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    /** Landing page: the first area this user may open. */
    public function home(): void
    {
        foreach (['dashboard.view' => 'dashboard', 'pos.access' => 'pos', 'inventory.view' => 'products', 'sales.view' => 'sales', 'reports.view' => 'reports'] as $perm => $route) {
            if (\App\Core\Auth::can($perm)) {
                Http::redirect(url($route));
            }
        }
        Http::redirect(url('account'));
    }

    public function index(): void
    {
        $from = Http::query('from');
        $to = Http::query('to');
        if (!Clock::isDate($from) || !Clock::isDate($to)) {
            $to = Clock::todayLocal();
            $from = Clock::nowLocal()->modify('-6 days')->format('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $this->view('pages/dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'from' => $from,
            'to' => $to,
            'd' => ReportService::dashboard($from, $to),
        ]);
    }
}
