<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Http;

/**
 * Server requirement checks shared by the installation wizard and Settings → System Check.
 *
 * Every check is an array:
 *   name    requirement name
 *   result  what was actually detected
 *   status  ok | warn | fail
 *   explain plain-language explanation (when not ok)
 *   action  what the user can do (when not ok)
 *
 * Results never contain absolute server paths or secrets; folders are shown relative to the
 * website folder (e.g. "pos/storage/logs/").
 */
final class Requirements
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Files that must be present for the application to run. */
    public const REQUIRED_FILES = [
        'index.php',
        '.htaccess',
        'app/bootstrap.php',
        'app/helpers.php',
        'app/Core/DB.php',
        'app/Core/Auth.php',
        'app/Services/SaleService.php',
        'app/Lib/SimplePdf.php',
        'app/views/layout/app.php',
        'assets/css/app.css',
        'assets/js/app.js',
        'assets/js/pos.js',
        'database/migrations/001_initial_schema.sql',
    ];

    /**
     * Writable folders the application uses.
     * level: what happens if it stays unwritable (fail = blocks installation).
     * files: safe default files the folder must contain (name => content).
     */
    public const DIRECTORIES = [
        'storage' => [
            'level' => self::FAIL,
            'purpose' => 'installation lock and private application data',
            'impact' => 'MotoSupply cannot record that it is installed, so installation cannot continue.',
            'files' => ['.htaccess' => self::DENY_ALL],
        ],
        'storage/logs' => [
            'level' => self::WARN,
            'purpose' => 'error logs (private)',
            'impact' => 'Errors will be written to the hosting provider\'s PHP error log instead. Everything else works.',
            'files' => ['.htaccess' => self::DENY_ALL, 'index.html' => ''],
        ],
        'storage/sessions' => [
            'level' => self::WARN,
            'purpose' => 'login sessions (private)',
            'impact' => 'Logins will use the hosting provider\'s default session storage instead. Everything else works.',
            'files' => ['.htaccess' => self::DENY_ALL, 'index.html' => ''],
        ],
        'uploads' => [
            'level' => self::WARN,
            'purpose' => 'uploaded files',
            'impact' => 'Product images cannot be uploaded until this is fixed. Everything else works.',
            'files' => ['.htaccess' => self::UPLOADS_HTACCESS, 'index.html' => ''],
        ],
        'uploads/products' => [
            'level' => self::WARN,
            'purpose' => 'product images',
            'impact' => 'Product images cannot be uploaded until this is fixed. Everything else works.',
            'files' => ['index.html' => ''],
        ],
    ];

    public const DENY_ALL = "# Deny all direct web access to this directory.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";

    public const UPLOADS_HTACCESS = "# Uploaded product images and shop branding (logo, favicon) only. Nothing in this folder may be executed.\n<IfModule mod_authz_core.c>\n  Require all denied\n  <FilesMatch \"\\.(jpe?g|png|gif|webp|ico)$\">\n    Require all granted\n  </FilesMatch>\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n  <FilesMatch \"\\.(jpe?g|png|gif|webp|ico)$\">\n    Order deny,allow\n    Allow from all\n  </FilesMatch>\n</IfModule>\n<IfModule mod_mime.c>\n  RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pl .py .cgi .shtml\n  RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\n</IfModule>\n<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options \"nosniff\"\n  Header always set Content-Security-Policy \"default-src 'none'; img-src 'self'\"\n</IfModule>\n";

    /**
     * Prove that PHP can create, write and delete a file in $dir. Uses a random file name
     * opened in exclusive mode, so an existing file can never be overwritten.
     */
    public static function writeTest(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $file = rtrim($dir, '/') . '/.moto-write-test-' . bin2hex(random_bytes(8));
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            return false;
        }
        $ok = @fwrite($fh, 'ok') === 2;
        @fclose($fh);
        $ok = $ok && @file_get_contents($file) === 'ok';
        $removed = @unlink($file);
        return $ok && $removed && !file_exists($file);
    }

    /** True when PHP runs as the owner of $path (so chmod is allowed). Unknown counts as "maybe". */
    private static function ownedByPhp(string $path): bool
    {
        $owner = @fileowner($path);
        if ($owner === false) {
            return false;
        }
        $me = function_exists('posix_geteuid') ? posix_geteuid() : @getmyuid();
        return $me === false || $owner === $me;
    }

    /** A folder that holds only MotoSupply's own placeholder files (safe to recreate). */
    private static function onlyPlaceholders(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $e) {
            if (in_array($e, ['.', '..', '.htaccess', 'index.html', '.gitkeep'], true) || str_starts_with($e, '.moto-write-test-')) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Prepare one folder: create it if missing, add its safe default files, and if PHP cannot
     * write to it, try safe fixes — chmod 0755, then 0775 (only when PHP owns the folder; never
     * 0777), then recreate it if it holds only placeholder files and its parent is writable.
     * Every attempt is verified with a real write test.
     *
     * @return array{exists:bool,writable:bool,created:bool,fixed:string}
     */
    public static function prepareDirectory(string $root, string $rel): array
    {
        $dir = $root . '/' . $rel;
        $spec = self::DIRECTORIES[$rel] ?? ['files' => []];
        $created = false;
        $fixed = '';
        if (!is_dir($dir)) {
            $old = umask(0022);
            $created = @mkdir($dir, 0755, true);
            umask($old);
        }
        if (is_dir($dir) && !self::writeTest($dir)) {
            if (self::ownedByPhp($dir)) {
                foreach ([0755, 0775] as $mode) {
                    if (@chmod($dir, $mode)) {
                        clearstatcache(true, $dir);
                        if (self::writeTest($dir)) {
                            $fixed = 'permissions set to ' . decoct($mode);
                            break;
                        }
                    }
                }
            }
            $parent = dirname($dir);
            if ($fixed === '' && self::onlyPlaceholders($dir) && self::writeTest($parent)) {
                // The folder was created with the wrong owner or mode (e.g. by an FTP upload).
                // Move it aside and recreate it as PHP's own folder.
                $aside = $parent . '/.' . basename($dir) . '-unwritable-' . bin2hex(random_bytes(3));
                if (@rename($dir, $aside)) {
                    $old = umask(0022);
                    $made = @mkdir($dir, 0755);
                    umask($old);
                    if ($made && self::writeTest($dir)) {
                        $fixed = 'folder recreated';
                        @unlink($aside . '/index.html');
                        @unlink($aside . '/.gitkeep');
                        @unlink($aside . '/.htaccess');
                        @rmdir($aside);
                    } else {
                        if (is_dir($dir)) {
                            @rmdir($dir);
                        }
                        @rename($aside, $dir); // put it back exactly as it was
                    }
                }
            }
        }
        $writable = is_dir($dir) && self::writeTest($dir);
        if ($writable) {
            foreach ($spec['files'] as $name => $content) {
                if (!file_exists($dir . '/' . $name)) {
                    @file_put_contents($dir . '/' . $name, $content);
                }
            }
        }
        return ['exists' => is_dir($dir), 'writable' => $writable, 'created' => $created, 'fixed' => $fixed];
    }

    /** Prepare every folder (and config/ when installing). Returns rel => result. */
    public static function prepareDirectories(bool $installing, string $root = MOTO_ROOT): array
    {
        $out = [];
        if ($installing) {
            $out['config'] = self::prepareDirectory($root, 'config');
        }
        foreach (array_keys(self::DIRECTORIES) as $rel) {
            $out[$rel] = self::prepareDirectory($root, $rel);
        }
        return $out;
    }

    /** Create missing runtime folders quietly (used on normal requests; no write tests). */
    public static function ensureDirectories(): void
    {
        foreach (array_keys(self::DIRECTORIES) as $rel) {
            if (!is_dir(MOTO_ROOT . '/' . $rel)) {
                self::prepareDirectory(MOTO_ROOT, $rel);
            }
        }
    }

    /** Folder path as the user sees it in the File Manager, relative to the website folder. */
    public static function displayPath(string $rel, string $root = MOTO_ROOT): string
    {
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $docReal = $docRoot !== '' ? @realpath($docRoot) : false;
        $rootReal = @realpath($root);
        $prefix = '';
        if ($docReal !== false && $rootReal !== false && str_starts_with($rootReal . '/', rtrim($docReal, '/') . '/')) {
            $prefix = trim(substr($rootReal, strlen(rtrim($docReal, '/'))), '/');
        }
        return '(website folder)/' . ($prefix !== '' ? $prefix . '/' : '') . $rel . '/';
    }

    private static function row(string $name, string $result, string $status, string $explain = '', string $action = ''): array
    {
        return ['name' => $name, 'result' => $result, 'status' => $status, 'explain' => $explain, 'action' => $action];
    }

    /** @return list<array{name:string,result:string,status:string,explain:string,action:string}> */
    public static function check(bool $installing, string $root = MOTO_ROOT): array
    {
        $c = [];
        $v = PHP_VERSION;
        if (version_compare($v, MOTO_MIN_PHP, '>=')) {
            $c[] = self::row('PHP version', "PHP $v", self::OK);
        } else {
            $c[] = self::row('PHP version', "PHP $v", self::FAIL,
                'MotoSupply needs PHP ' . MOTO_MIN_PHP . ' or newer.',
                'Choose PHP 8.3 or 8.4 in your hosting control panel (PHP version selector / MultiPHP Manager), or ask your host.');
        }

        $ext = function (string $name, string $label, string $level, string $explain) use (&$c): void {
            $c[] = extension_loaded($name)
                ? self::row($label, 'Available', self::OK)
                : self::row($label, 'Not available', $level, $explain,
                    'Enable the "' . $name . '" PHP extension in your hosting control panel (Select PHP Version → Extensions), or ask your host.');
        };
        $ext('pdo', 'PDO extension', self::FAIL, 'MotoSupply uses PDO to talk to the database.');
        $ext('pdo_mysql', 'PDO MySQL driver', self::FAIL, 'This driver is needed to connect to the MySQL database.');
        $ext('mbstring', 'mbstring extension', self::FAIL, 'Needed to handle product names and text correctly.');
        $c[] = function_exists('json_encode')
            ? self::row('JSON support', 'Available', self::OK)
            : self::row('JSON support', 'Not available', self::FAIL, 'The POS screen exchanges data as JSON.', 'Enable the "json" extension, or ask your host.');
        $ext('fileinfo', 'fileinfo extension', self::WARN, 'Used to check uploaded product images. Without it, image uploads are disabled; everything else works.');
        $c[] = function_exists('gzcompress')
            ? self::row('zlib (PDF compression)', 'Available', self::OK)
            : self::row('zlib (PDF compression)', 'Not available', self::WARN, 'PDF reports still work but the files are larger.', 'Optional: enable the "zlib" extension.');
        $c[] = function_exists('password_hash') && function_exists('random_bytes')
            ? self::row('Password hashing & secure random', 'Available', self::OK)
            : self::row('Password hashing & secure random', 'Not available', self::FAIL, 'Needed to store passwords safely.', 'Ask your host for a standard PHP 8 build.');
        $c[] = class_exists(\ZipArchive::class)
            ? self::row('zip extension (updates and backups)', 'Available', self::OK)
            : self::row('zip extension (updates and backups)', 'Not available', self::WARN,
                'Needed for the in-app updater and file backups. Without it, install updates by uploading the files by hand (README "Updating").',
                'Enable the "zip" extension in your hosting control panel, or ask your host.');
        $c[] = function_exists('sodium_crypto_sign_verify_detached')
            ? self::row('sodium extension (update signatures)', 'Available', self::OK)
            : self::row('sodium extension (update signatures)', 'Not available', self::WARN,
                'Needed to verify that update packages are genuine. Without it, the in-app updater is disabled; manual updates still work.',
                'Enable the "sodium" extension in your hosting control panel, or ask your host.');
        $c[] = function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt')
            ? self::row('Encryption (stored SMTP password)', 'Available', self::OK)
            : self::row('Encryption (stored SMTP password)', 'Not available', self::WARN,
                'The SMTP password for email reports cannot be stored encrypted, so SMTP sign-in is disabled. Everything else works.',
                'Enable the "sodium" or "openssl" extension, or ask your host.');
        $c[] = session_status() === PHP_SESSION_ACTIVE
            ? self::row('PHP sessions', 'Working', self::OK)
            : self::row('PHP sessions', 'Not working', self::FAIL, 'Sessions keep you logged in.', 'Allow cookies for this site in your browser, then click Recheck Requirements. If it persists, ask your host to enable PHP sessions.');

        $missing = array_values(array_filter(self::REQUIRED_FILES, static fn ($f) => !is_file($root . '/' . $f)));
        $c[] = $missing === []
            ? self::row('Application files', 'All present', self::OK)
            : self::row('Application files', 'Missing: ' . implode(', ', array_slice($missing, 0, 4)) . (count($missing) > 4 ? '…' : ''), self::FAIL,
                'Some files did not upload completely.',
                'Upload MotoSupply-POS-Installer.zip again and use the File Manager\'s Extract function, then click Recheck Requirements.');
        $ht = ['.htaccess', 'app/.htaccess', 'config/.htaccess', 'database/.htaccess', 'storage/.htaccess', 'uploads/.htaccess'];
        $missingHt = array_values(array_filter($ht, static fn ($f) => !is_file($root . '/' . $f)));
        $c[] = $missingHt === []
            ? self::row('Security rules (.htaccess files)', 'Present', self::OK)
            : self::row('Security rules (.htaccess files)', 'Missing: ' . implode(', ', $missingHt), self::FAIL,
                'These hidden files stop visitors from opening private files. Some were not uploaded.',
                'Use the File Manager\'s Extract function (it keeps hidden files), or turn on "show hidden files" in your FTP program and upload again.');

        // Folders: prepare automatically, then report the verified result.
        foreach (self::prepareDirectories($installing, $root) as $rel => $r) {
            $spec = self::DIRECTORIES[$rel] ?? [
                'level' => self::FAIL,
                'purpose' => 'configuration (database settings)',
                'impact' => 'The installer cannot save the database settings, so installation cannot continue.',
            ];
            $name = 'Folder ' . $rel . '/ (' . $spec['purpose'] . ')';
            if ($r['writable']) {
                $result = 'Writable (write test passed)' . ($r['created'] ? '; folder created' : '') . ($r['fixed'] !== '' ? '; fixed automatically: ' . $r['fixed'] : '');
                $c[] = self::row($name, $result, self::OK);
                continue;
            }
            $path = self::displayPath($rel, $root);
            $c[] = self::row($name, $r['exists'] ? 'Not writable (write test failed)' : 'Missing and could not be created', $spec['level'],
                $spec['impact'] . ' The installer tried to fix this automatically but the hosting account does not allow it.',
                'Open your hosting File Manager → go to ' . $path . ' → right-click the folder → Permissions (or "Change Permissions") → set 755 '
                . '(owner: read, write, execute). If 755 does not work, try 775. Never use 777. '
                . ($r['exists'] ? '' : 'If the folder is missing, create it first with "New Folder". ')
                . 'Then click Recheck Requirements.');
        }

        if (!$installing) {
            try {
                $bad = \App\Core\DB::nonTransactionalTables();
                $c[] = $bad === []
                    ? self::row('Database tables (InnoDB, transactions)', 'All tables support transactions', self::OK)
                    : self::row('Database tables (InnoDB, transactions)', 'Not transactional: ' . implode(', ', array_slice($bad, 0, 6)), self::FAIL,
                        'Without transactions a failed sale can leave partial records, so sales and stock changes are paused.',
                        'In phpMyAdmin, open each listed table → Operations → Storage Engine → InnoDB → Go. Then open Products → Integrity check.');
            } catch (\Throwable) {
                // Database unavailable: reported elsewhere.
            }
        }

        $c[] = self::httpsRow();
        return $c;
    }

    /** HTTPS status: ok when active, warning (testing only) otherwise. */
    public static function httpsRow(): array
    {
        if (Http::isHttps()) {
            return self::row('HTTPS (SSL)', 'Active', self::OK);
        }
        $proxy = Http::untrustedProxySaysHttps()
            ? ' Your host appears to use a proxy that reports HTTPS; see "HTTPS behind a proxy" in README.md to make MotoSupply trust it.'
            : '';
        return self::row('HTTPS (SSL)', 'Not active: this connection is not encrypted', self::WARN,
            'You may install over HTTP only as a test. Do not enter real passwords or business data until HTTPS is active.' . $proxy,
            'In your hosting panel, install the free SSL certificate (InfinityFree: Client Area → Free SSL Certificates; cPanel: SSL/TLS Status → Run AutoSSL). '
            . 'When https:// works, open the site with https:// and turn on "Require HTTPS" in Settings → System Check.');
    }

    public static function hasFailures(array $checks): bool
    {
        return in_array(self::FAIL, array_column($checks, 'status'), true);
    }
}
