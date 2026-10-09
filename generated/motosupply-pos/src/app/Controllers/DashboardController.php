<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Http;
use App\Services\ReportService;

final class DashboardController extends Controller
{
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
