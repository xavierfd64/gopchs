<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http;
use App\Services\ReportExporter;
use App\Services\ReportService;

final class ReportController extends Controller
{
    private function request(): array
    {
        return ReportService::normalize([
            'type' => Http::query('type', 'sales'),
            'group' => Http::query('group', 'day'),
            'from' => Http::query('from'),
            'to' => Http::query('to'),
            'preset' => Http::query('preset'),
        ]);
    }

    public function index(): void
    {
        $req = $this->request();
        $report = ReportService::build($req);
        // Paginate the on-screen table only; exports contain every row.
        $perPage = 50;
        $total = count($report['rows']);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $this->idParam('page') ?: 1), $pages);
        $this->view('pages/reports', [
            'title' => 'Reports',
            'nav' => 'reports',
            'req' => $req,
            'report' => $report,
            'rows' => array_slice($report['rows'], ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function export(): void
    {
        $req = $this->request();
        $report = ReportService::build($req);
        $format = Http::query('format') === 'pdf' ? 'pdf' : 'csv';
        $filename = ReportExporter::filename($report, $req, $format);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        if ($format === 'pdf') {
            $pdf = ReportExporter::pdf($report);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        ReportExporter::csv($report, $out);
        fclose($out);
    }
}
