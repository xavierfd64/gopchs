<?php
declare(strict_types=1);

// Helper process for tests/run.php: attempts one sale of 1 unit at a synchronized start time.
define('MOTO_ROOT', dirname(__DIR__) . '/src');
require MOTO_ROOT . '/app/bootstrap.php';
restore_exception_handler();

use App\Core\Config;
use App\Core\ValidationException;
use App\Services\SaleService;

[, $db, $productId, $token, $startAt] = $argv;
Config::load(['db' => json_decode($db, true), 'app' => []]);
\App\Core\DB::pdo();
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}
try {
    SaleService::checkout([['product_id' => (int) $productId, 'quantity' => 1]], 'none', '0', '100000', $token, 1);
    echo 'OK';
} catch (ValidationException $e) {
    echo 'REJECTED';
}
