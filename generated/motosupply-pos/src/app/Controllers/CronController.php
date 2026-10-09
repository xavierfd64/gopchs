<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http;
use App\Core\Settings;
use App\Services\EmailReports;

/**
 * Scheduled-task URL for hosts without cron (use an external scheduler such as cron-job.org):
 *   https://your-site/index.php?r=cron.daily-report&key=SECRET
 * Requires HTTPS and the secret generated in Settings → Email reports (only its hash is stored).
 * Calling it repeatedly never sends twice: it only sends a report that is due and not yet sent.
 */
final class CronController extends Controller
{
    public function dailyReport(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        $key = Http::query('key') !== '' ? Http::query('key') : Http::post('key');
        $hash = Settings::get('email_cron_key_hash');
        if (!Http::isHttps()) {
            http_response_code(403);
            exit('HTTPS required');
        }
        if ($hash === '' || strlen($key) < 32 || !hash_equals($hash, hash('sha256', $key))) {
            usleep(random_int(200000, 500000)); // slow down guessing
            http_response_code(403);
            exit('forbidden');
        }
        $result = EmailReports::runScheduled('url');
        http_response_code(str_starts_with($result, 'failed') ? 500 : 200);
        echo str_starts_with($result, 'failed') ? 'failed' : $result;
    }
}
