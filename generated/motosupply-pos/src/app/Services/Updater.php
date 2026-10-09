<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;

/**
 * Browser-based application updates from a signed ZIP package.
 *
 * Package layout (MotoSupply-POS-Update.zip):
 *   motosupply-update.json   manifest: product, type, version, min_version, changelog, files{path: sha256}, remove[]
 *   motosupply-update.sig    base64 Ed25519 signature of the exact manifest bytes
 *   <files listed in the manifest at their install paths>
 *   UPDATE-README.md / CHANGELOG.md (documentation, never installed)
 *
 * Safety: signature from a trusted release key; SHA-256 of every file; no path traversal,
 * absolute paths, symlinks, duplicates or unlisted files; only application paths may be written
 * (never config/, storage/, uploads/ contents); no downgrades. Files are installed only after a
 * database dump and a ZIP of the current application files were written to storage/backups/.
 */
final class Updater
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_ENTRIES = 3000;
    public const MANIFEST = 'motosupply-update.json';
    public const SIGNATURE = 'motosupply-update.sig';
    public const DOCS = ['UPDATE-README.md', 'CHANGELOG.md'];
    /** Paths an update may write: directory prefixes and exact files. */
    public const ALLOWED_PREFIXES = ['app/', 'assets/', 'database/migrations/'];
    public const ALLOWED_FILES = ['index.php', '.htaccess', 'database/.htaccess', 'config/.htaccess', 'config/config.sample.php', 'storage/.htaccess', 'uploads/.htaccess'];
    /** Application code captured in the pre-update file backup (restored on failure/rollback). */
    public const BACKUP_PATHS = ['index.php', '.htaccess', 'app', 'assets', 'database', 'config/.htaccess', 'config/config.sample.php', 'storage/.htaccess', 'uploads/.htaccess'];

    /** Base64 Ed25519 public keys of trusted MotoSupply releases (plus optional keys from config). */
    public static function trustedKeys(): array
    {
        $extra = Config::get('update_trusted_keys', []);
        return array_values(array_unique(array_merge(UpdateKeys::KEYS, is_array($extra) ? array_filter($extra, 'is_string') : [])));
    }

    public static function requirements(): array
    {
        $out = [];
        if (!class_exists(\ZipArchive::class)) {
            $out[] = 'The PHP "zip" extension is not available, so updates must be uploaded by hand (see README "Updating").';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $out[] = 'The PHP "sodium" extension is not available, so update signatures cannot be verified. Updates must be uploaded by hand.';
        }
        return $out;
    }

    public static function validPath(string $p): bool
    {
        if ($p === '' || strlen($p) > 255 || str_contains($p, "\0") || str_contains($p, '\\') || str_starts_with($p, '/')
            || preg_match('#(^|/)\.\.?(/|$)#', $p) || preg_match('#^[A-Za-z]:#', $p) || str_contains($p, '//')) {
            return false;
        }
        return (bool) preg_match('#^[A-Za-z0-9._\-/]+$#', $p);
    }

    public static function allowedTarget(string $p): bool
    {
        if (!self::validPath($p) || str_ends_with($p, '/')) {
            return false;
        }
        if (in_array($p, self::ALLOWED_FILES, true)) {
            return true;
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($p, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** Store an uploaded package; returns a token. */
    public static function stash(?array $file): string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('Choose the update ZIP file to upload. If it is large, your host\'s upload limit may be too low.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('The file is larger than 20 MB, which is not a MotoSupply update package.');
        }
        $dir = self::workDir();
        $token = bin2hex(random_bytes(16));
        if (!move_uploaded_file((string) $file['tmp_name'], "$dir/$token.zip")) {
            throw new \RuntimeException('The package could not be stored in storage/updates/. Check Settings → System Check.');
        }
        return $token;
    }

    public static function workDir(): string
    {
        $dir = MOTO_ROOT . '/storage/updates';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file("$dir/.htaccess")) {
            @file_put_contents("$dir/.htaccess", Requirements::DENY_ALL);
        }
        foreach (glob("$dir/*.zip") ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        return $dir;
    }

    public static function packagePath(string $token): ?string
    {
        $p = MOTO_ROOT . '/storage/updates/' . $token . '.zip';
        return preg_match('/^[a-f0-9]{32}$/', $token) && is_file($p) ? $p : null;
    }

    /**
     * Fully validate a package. Returns the manifest plus a summary, or throws RuntimeException
     * with a clear reason. Nothing is extracted to disk.
     */
    public static function inspect(string $path): array
    {
        if ($r = self::requirements()) {
            throw new \RuntimeException(implode(' ', $r));
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException('The package is too large.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('This is not a valid ZIP file, or it is damaged.');
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('The ZIP has an unexpected number of entries.');
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string) ($st['name'] ?? '');
                if (!self::validPath(rtrim($name, '/'))) {
                    throw new \RuntimeException('Rejected: the package contains an unsafe path ("' . mb_substr($name, 0, 80) . '").');
                }
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
                    throw new \RuntimeException('Rejected: the package contains a symbolic link ("' . mb_substr($name, 0, 80) . '").');
                }
                if (isset($names[$name])) {
                    throw new \RuntimeException('Rejected: duplicate entry "' . mb_substr($name, 0, 80) . '".');
                }
                $names[$name] = $i;
            }
            $manifestRaw = $zip->getFromName(self::MANIFEST);
            $sig = $zip->getFromName(self::SIGNATURE);
            if ($manifestRaw === false || $sig === false) {
                throw new \RuntimeException('This is not a MotoSupply update package (manifest or signature missing). Fresh-install packages cannot be used to update.');
            }
            $sigBin = base64_decode(trim($sig), true);
            $verified = false;
            foreach (self::trustedKeys() as $key) {
                $pub = base64_decode($key, true);
                if ($sigBin !== false && $pub !== false && strlen($pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                    && strlen($sigBin) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($sigBin, $manifestRaw, $pub)) {
                    $verified = true;
                    break;
                }
            }
            if (!$verified) {
                throw new \RuntimeException('Rejected: the package signature is not valid. Only official MotoSupply update packages signed with a trusted release key can be installed.');
            }
            $m = json_decode($manifestRaw, true);
            if (!is_array($m) || ($m['product'] ?? '') !== 'motosupply-pos' || ($m['type'] ?? '') !== 'update'
                || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? '')) || !is_array($m['files'] ?? null)) {
                throw new \RuntimeException('Rejected: the manifest is not a valid MotoSupply update manifest.');
            }
            $cmp = version_compare($m['version'], MOTO_VERSION);
            if ($cmp <= 0) {
                throw new \RuntimeException('Rejected: version ' . $m['version'] . ' is not newer than the installed version ' . MOTO_VERSION . '. Downgrades are not allowed (see README "Recovery" to restore a backup).');
            }
            if (isset($m['min_version']) && version_compare(MOTO_VERSION, (string) $m['min_version']) < 0) {
                throw new \RuntimeException('This update requires version ' . $m['min_version'] . ' or newer. Install the intermediate update first.');
            }
            foreach ($m['files'] as $p => $hash) {
                if (!is_string($p) || !self::allowedTarget($p) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
                    throw new \RuntimeException('Rejected: the package tries to write a file outside the application code ("' . mb_substr((string) $p, 0, 80) . '"). Configuration, uploads and data are never replaced.');
                }
                if (!isset($names[$p])) {
                    throw new \RuntimeException("Rejected: $p is listed in the manifest but missing from the package.");
                }
                $data = $zip->getFromIndex($names[$p]);
                if ($data === false || !hash_equals($hash, hash('sha256', $data))) {
                    throw new \RuntimeException("Rejected: $p does not match its checksum (the package was modified or damaged).");
                }
            }
            foreach ($m['remove'] ?? [] as $p) {
                if (!is_string($p) || !self::allowedTarget($p)) {
                    throw new \RuntimeException('Rejected: the package asks to delete a protected file.');
                }
            }
            foreach ($names as $n => $_) {
                if (!str_ends_with($n, '/') && !isset($m['files'][$n]) && !in_array($n, array_merge([self::MANIFEST, self::SIGNATURE], self::DOCS), true)) {
                    throw new \RuntimeException('Rejected: the package contains an unlisted file ("' . mb_substr($n, 0, 80) . '").');
                }
            }
            $newMigrations = array_values(array_filter(array_keys($m['files']), static fn ($p) => str_starts_with($p, 'database/migrations/')
                && !is_file(MOTO_ROOT . '/' . $p) && preg_match('#/\d{3}_[a-z0-9_]+\.(sql|php)$#', $p)));
            $changed = 0;
            $added = 0;
            foreach ($m['files'] as $p => $hash) {
                $cur = MOTO_ROOT . '/' . $p;
                if (!is_file($cur)) {
                    $added++;
                } elseif (hash_file('sha256', $cur) !== $hash) {
                    $changed++;
                }
            }
            return [
                'manifest' => $m,
                'version' => $m['version'],
                'from' => MOTO_VERSION,
                'files' => count($m['files']),
                'changed' => $changed,
                'added' => $added,
                'remove' => count($m['remove'] ?? []),
                'migrations' => $newMigrations,
                'changelog' => is_string($m['changelog'] ?? null) ? mb_substr($m['changelog'], 0, 5000) : '',
            ];
        } finally {
            $zip->close();
        }
    }

    /** Every target must be writable before anything is changed. */
    private static function preflight(array $m): void
    {
        $problems = [];
        foreach (array_merge(array_keys($m['files']), $m['remove'] ?? []) as $p) {
            $target = MOTO_ROOT . '/' . $p;
            $dir = dirname($target);
            while (!is_dir($dir) && $dir !== MOTO_ROOT && strlen($dir) > strlen(MOTO_ROOT)) {
                $dir = dirname($dir);
            }
            if (!Requirements::writeTest($dir) || (is_file($target) && !is_writable($target))) {
                $problems[dirname($p)] = true;
            }
        }
        if ($problems) {
            throw new \RuntimeException('Nothing was changed: PHP cannot write to these folders: ' . implode(', ', array_slice(array_keys($problems), 0, 8))
                . '. Fix their permissions (755) in the File Manager, or upload the update files by hand (README "Updating").');
        }
    }

    /**
     * Install a validated package. Returns ['ok' => bool, 'log' => list<string>, 'history_id' => int].
     */
    public static function apply(string $path, int $userId): array
    {
        $log = [];
        $info = self::inspect($path); // re-validate right before installing
        $m = $info['manifest'];
        $log[] = 'Package verified: version ' . $m['version'] . ', ' . $info['files'] . ' files, signature valid.';
        self::preflight($m);
        $log[] = 'Write permissions checked.';

        $stamp = gmdate('Ymd-His');
        $backupDir = Backup::dir() . "/update-{$stamp}-from-" . MOTO_VERSION;
        if (!@mkdir($backupDir, 0755, true)) {
            throw new \RuntimeException('Nothing was changed: the backup folder could not be created in storage/backups/.');
        }
        $dbBackup = Backup::database('before-update-' . MOTO_VERSION);
        @rename($dbBackup, $backupDir . '/database.sql' . (str_ends_with($dbBackup, '.gz') ? '.gz' : ''));
        $fileCount = Backup::files(self::BACKUP_PATHS, $backupDir . '/files.zip');
        $log[] = "Backup created: database dump and $fileCount application files.";
        $now = Clock::nowUtc();
        $historyId = DB::insert(
            'INSERT INTO update_history (from_version, to_version, status, backup_dir, details, user_id, created_at, updated_at) VALUES (?, ?, \'started\', ?, ?, ?, ?, ?)',
            [MOTO_VERSION, $m['version'], basename($backupDir), json_encode(['files' => $info['files']]), $userId, $now, $now]
        );

        $flag = MOTO_ROOT . '/storage/maintenance.flag';
        @file_put_contents($flag, 'Updating to ' . $m['version'] . ' since ' . gmdate('c'));
        $staging = self::workDir() . '/staging-' . bin2hex(random_bytes(6));
        $installed = false;
        try {
            // Stage: write each verified file into a private staging folder (no extractTo()).
            $zip = new \ZipArchive();
            $zip->open($path);
            foreach ($m['files'] as $p => $hash) {
                $data = $zip->getFromName($p);
                if ($data === false || !hash_equals($hash, hash('sha256', $data))) {
                    throw new \RuntimeException("Staging failed for $p.");
                }
                $dest = "$staging/$p";
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
                    throw new \RuntimeException('Could not create the staging folder.');
                }
                file_put_contents($dest, $data);
            }
            $zip->close();
            $log[] = 'Files staged and verified.';

            // Install: atomic per-file replace (temp file + rename in the target folder).
            $installed = true;
            foreach ($m['files'] as $p => $hash) {
                $target = MOTO_ROOT . '/' . $p;
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
                    throw new \RuntimeException("Could not create folder for $p.");
                }
                $tmp = $target . '.upd-' . bin2hex(random_bytes(3));
                if (!@copy("$staging/$p", $tmp) || !@rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new \RuntimeException("Could not write $p.");
                }
            }
            foreach ($m['remove'] ?? [] as $p) {
                if (is_file(MOTO_ROOT . '/' . $p)) {
                    @unlink(MOTO_ROOT . '/' . $p);
                }
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $log[] = 'Application files installed.';

            $ran = (new Migrator(DB::pdo()))->migrate();
            $log[] = $ran ? 'Database migrations applied: ' . implode(', ', $ran) . '.' : 'No database migrations were needed.';

            // Post-update checks.
            $bootstrap = (string) file_get_contents(MOTO_ROOT . '/app/bootstrap.php');
            if (!preg_match("/const MOTO_VERSION = '([^']+)'/", $bootstrap, $vm) || $vm[1] !== $m['version']) {
                throw new \RuntimeException('Post-update check failed: the installed version does not match the package.');
            }
            foreach (Requirements::REQUIRED_FILES as $f) {
                if (!is_file(MOTO_ROOT . '/' . $f)) {
                    throw new \RuntimeException("Post-update check failed: $f is missing.");
                }
            }
            if (Migrator::pending() !== []) {
                throw new \RuntimeException('Post-update check failed: database migrations are still pending.');
            }
            $log[] = 'Post-update checks passed.';
            DB::run("UPDATE update_history SET status = 'success', details = ?, updated_at = ? WHERE id = ?", [json_encode(['log' => $log]), Clock::nowUtc(), $historyId]);
            return ['ok' => true, 'log' => $log, 'history_id' => $historyId, 'version' => $m['version']];
        } catch (\Throwable $e) {
            Logger::error('Update to ' . $m['version'] . ' failed', $e);
            $log[] = 'FAILED: ' . ($e instanceof \RuntimeException ? $e->getMessage() : 'unexpected error (see storage/logs)');
            if ($installed) {
                try {
                    self::restoreFiles($backupDir . '/files.zip');
                    $log[] = 'The previous application files were restored automatically from the backup.';
                } catch (\Throwable $re) {
                    Logger::error('Automatic file restore failed', $re);
                    $log[] = 'Automatic restore FAILED. Restore the files from storage/backups/' . basename($backupDir) . '/files.zip by hand (README "Recovery").';
                }
                $log[] = 'Database: if a migration ran partially, the dump in storage/backups/' . basename($backupDir) . '/ can be imported with phpMyAdmin. Migrations only add tables/columns, so the previous version keeps working.';
            } else {
                $log[] = 'No application files were changed.';
            }
            DB::run("UPDATE update_history SET status = 'failed', details = ?, updated_at = ? WHERE id = ?", [json_encode(['log' => $log]), Clock::nowUtc(), $historyId]);
            return ['ok' => false, 'log' => $log, 'history_id' => $historyId, 'version' => $m['version']];
        } finally {
            @unlink($flag);
            self::removeTree($staging);
        }
    }

    /** Restore application files from a backup ZIP made by apply() (same path checks as packages). */
    public static function restoreFiles(string $zipFile): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Backup archive not found or unreadable.');
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $ok = self::validPath($name) && (self::allowedTarget($name) || str_starts_with($name, 'database/'));
            if (!$ok) {
                continue;
            }
            $target = MOTO_ROOT . '/' . $name;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            $tmp = $target . '.rst-' . bin2hex(random_bytes(3));
            if (file_put_contents($tmp, (string) $zip->getFromIndex($i)) === false || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException("Could not restore $name.");
            }
            $n++;
        }
        $zip->close();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return $n;
    }

    /** Roll back the files of a past update (database left as is; migrations are additive). */
    public static function rollback(int $historyId): array
    {
        $h = DB::one('SELECT * FROM update_history WHERE id = ?', [$historyId]);
        if ($h === null || !in_array($h['status'], ['success', 'failed'], true)) {
            throw new \RuntimeException('This update cannot be rolled back.');
        }
        $dir = Backup::dir() . '/' . basename((string) $h['backup_dir']);
        $n = self::restoreFiles($dir . '/files.zip');
        DB::run("UPDATE update_history SET status = 'rolled_back', updated_at = ? WHERE id = ?", [Clock::nowUtc(), $historyId]);
        return ['files' => $n, 'version' => $h['from_version']];
    }

    public static function history(): array
    {
        try {
            return DB::all('SELECT h.*, u.username FROM update_history h LEFT JOIN users u ON u.id = h.user_id ORDER BY h.id DESC LIMIT 20');
        } catch (\Throwable) {
            return [];
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
