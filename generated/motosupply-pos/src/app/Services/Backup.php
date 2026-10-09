<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Backups made with PHP only (no mysqldump or shell): a SQL dump of the database and a ZIP of
 * application files. Stored under storage/backups/ (denied to web visitors).
 */
final class Backup
{
    public static function dir(): string
    {
        $dir = MOTO_ROOT . '/storage/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', Requirements::DENY_ALL);
        }
        return $dir;
    }

    /** Write a full SQL dump (structure + data) of the current database. Returns the file path. */
    public static function database(string $label): string
    {
        $pdo = DB::pdo();
        $file = self::dir() . '/' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9.\-]/i', '', $label) . '-' . bin2hex(random_bytes(3)) . '.sql' . (function_exists('gzopen') ? '.gz' : '');
        $gz = function_exists('gzopen');
        $fh = $gz ? gzopen($file, 'wb6') : fopen($file, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('backup not writable');
        }
        $w = static fn (string $s) => $gz ? gzwrite($fh, $s) : fwrite($fh, $s);
        $w("-- MotoSupply POS database backup\n-- Created " . gmdate('c') . " (UTC), app version " . MOTO_VERSION . "\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(\PDO::FETCH_NUM)[1];
            $w("DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            $batch = [];
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)) . ')';
                if (count($batch) >= 200) {
                    $w("INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $w("INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS = 1;\n");
        $gz ? gzclose($fh) : fclose($fh);
        @chmod($file, 0640);
        return $file;
    }

    /**
     * ZIP the given application paths (relative to MOTO_ROOT). Missing paths are skipped.
     * @param list<string> $paths
     */
    public static function files(array $paths, string $zipFile): int
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('zip extension missing');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('cannot create backup zip');
        }
        $count = 0;
        foreach ($paths as $rel) {
            $abs = MOTO_ROOT . '/' . $rel;
            if (is_file($abs)) {
                $zip->addFile($abs, $rel);
                $count++;
                continue;
            }
            if (!is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) {
                    $zip->addFile($f->getPathname(), $rel . '/' . substr($f->getPathname(), strlen($abs) + 1));
                    $count++;
                }
            }
        }
        $zip->close();
        @chmod($zipFile, 0640);
        return $count;
    }
}
