<?php
declare(strict_types=1);

/*
 * Daily email report for hosting cron jobs (cPanel → Cron Jobs). Command line only:
 *   php /home/USER/public_html/app/cli/daily-report.php
 * Run it every 15 minutes or hourly; it sends each day's report once, after the configured time.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('MOTO_ROOT', dirname(__DIR__, 2));
require MOTO_ROOT . '/app/bootstrap.php';

App\Core\Config::load();
if (!App\Core\Config::exists()) {
    fwrite(STDERR, "MotoSupply is not installed.\n");
    exit(1);
}
$result = App\Services\EmailReports::runScheduled('cron');
echo $result . PHP_EOL;
exit(str_starts_with($result, 'failed') ? 1 : 0);
