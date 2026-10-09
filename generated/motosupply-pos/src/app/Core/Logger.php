<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Minimal file logger. Never pass passwords, session IDs or tokens to it. */
final class Logger
{
    public static function error(string $message, ?Throwable $e = null): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR ' . $message;
        if ($e !== null) {
            $line .= ': ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
        }
        self::write($line);
    }

    /** Returns true when written to storage/logs, false when the PHP error log fallback was used. */
    public static function info(string $message): bool
    {
        return self::write('[' . gmdate('Y-m-d H:i:s') . ' UTC] INFO ' . $message);
    }

    /**
     * Append to storage/logs/app-YYYY-MM.log. If that folder is not writable, fall back to the
     * hosting provider's private PHP error log so the application never fails because of logging.
     */
    private static function write(string $line): bool
    {
        $dir = MOTO_ROOT . '/storage/logs';
        $line = str_replace(["\r", "\n"], ' ', $line) . PHP_EOL;
        if (is_dir($dir) && @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX) !== false) {
            return true;
        }
        @error_log('MotoSupply: ' . trim($line));
        return false;
    }
}
