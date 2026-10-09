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

    public static function info(string $message): void
    {
        self::write('[' . gmdate('Y-m-d H:i:s') . ' UTC] INFO ' . $message);
    }

    private static function write(string $line): void
    {
        $dir = MOTO_ROOT . '/storage/logs';
        $line = str_replace(["\r", "\n"], ' ', $line) . PHP_EOL;
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        } else {
            error_log(trim($line));
        }
    }
}
