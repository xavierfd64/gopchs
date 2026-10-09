<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\View;

/**
 * Keeps the database schema in step with the code. When files were updated (by FTP or the
 * browser updater) and migrations are pending, they are applied once — after a database
 * backup — under a lock. Migrations only add tables/columns; they never delete business data.
 */
final class SchemaUpdater
{
    public static function ensureCurrent(): void
    {
        if (Migrator::pending() === []) {
            return;
        }
        $lock = @fopen(MOTO_ROOT . '/storage/migrate.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            self::busy();
        }
        try {
            if (Migrator::pending() === []) {
                return; // another request finished it
            }
            $backup = '';
            try {
                $backup = basename(Backup::database('before-migration'));
            } catch (\Throwable $e) {
                Logger::error('Pre-migration backup failed (continuing; migrations are additive)', $e);
            }
            try {
                $ran = (new Migrator(DB::pdo()))->migrate();
            } catch (\Throwable $e) {
                Logger::error('Automatic database update failed', $e);
                http_response_code(503);
                View::render('pages/error', [
                    'title' => 'Database update needed',
                    'message' => 'MotoSupply could not finish updating its database. No business records were deleted. '
                        . 'Ask the administrator to check storage/logs/ and the backup in storage/backups/'
                        . ($backup !== '' ? ' (' . $backup . ')' : '') . '. Reloading this page retries the update safely.',
                ], 'layout/guest');
                exit;
            }
            Settings::reset();
            Logger::info('Database migrations applied: ' . implode(', ', $ran) . ($backup !== '' ? " (backup $backup)" : ''));
            Audit::log('system.migrate', 'schema', (string) max($ran ?: [0]), ['versions' => $ran, 'backup' => $backup], 'success', ['id' => null, 'username' => 'system']);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function busy(): never
    {
        http_response_code(503);
        header('Retry-After: 30');
        View::render('pages/error', ['title' => 'Updating', 'message' => 'MotoSupply is updating its database. Please try again in a moment.'], 'layout/guest');
        exit;
    }
}
