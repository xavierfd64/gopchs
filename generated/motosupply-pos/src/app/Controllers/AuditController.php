<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Http;

final class AuditController extends Controller
{
    public function index(): void
    {
        $from = Http::query('from');
        $to = Http::query('to');
        $f = [
            'action' => mb_substr(Http::query('action'), 0, 64),
            'user' => mb_substr(Http::query('user'), 0, 50),
            'status' => Http::query('status'),
            'page' => $this->idParam('page') ?: 1,
            'from' => Clock::isDate($from) ? $from : '',
            'to' => Clock::isDate($to) ? $to : '',
        ];
        if ($f['from'] !== '' && $f['to'] !== '') {
            [$f['from_utc'], $f['to_utc']] = Clock::localRangeToUtc(min($f['from'], $f['to']), max($f['from'], $f['to']));
        }
        $this->view('pages/audit', ['title' => 'Audit log', 'nav' => 'settings', 'f' => $f, 'list' => Audit::list($f)]);
    }
}
