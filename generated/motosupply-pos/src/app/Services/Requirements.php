<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Http;

/**
 * Server requirement checks shared by the installation wizard and Settings → System Check.
 * Each check: [label, status pass|warn|fail, detail, fix]. Details never reveal paths or secrets.
 */
final class Requirements
{
    public const PASS = 'pass';
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

    /** Create runtime folders that may be missing after an FTP upload (empty folders are sometimes skipped). */
    public static function ensureDirectories(): void
    {
        foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'uploads', 'uploads/products'] as $dir) {
            $path = MOTO_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }

    /** @return list<array{0:string,1:string,2:string,3:string}> */
    public static function check(bool $installing): array
    {
        $c = [];
        $v = PHP_VERSION;
        if (version_compare($v, '8.3.0', '>=')) {
            $c[] = ['PHP version', self::PASS, "PHP $v", ''];
        } elseif (version_compare($v, MOTO_MIN_PHP, '>=')) {
            $c[] = ['PHP version', self::WARN, "PHP $v (8.3 recommended)", 'The system works, but select PHP 8.3 in your hosting panel (PHP version / MultiPHP Manager) if available.'];
        } else {
            $c[] = ['PHP version', self::FAIL, "PHP $v", 'PHP ' . MOTO_MIN_PHP . ' or newer is required. Choose a newer PHP version in your hosting control panel, or ask your host.'];
        }

        $ext = static fn (string $name, string $label, string $level, string $fix): array =>
            extension_loaded($name) ? [$label, self::PASS, 'Available', ''] : [$label, $level, 'Not available', $fix];
        $c[] = $ext('pdo', 'PDO database extension', self::FAIL, 'Enable the "pdo" extension in your hosting PHP settings, or ask your host.');
        $c[] = $ext('pdo_mysql', 'PDO MySQL driver', self::FAIL, 'Enable the "pdo_mysql" extension in your hosting PHP settings, or ask your host.');
        $c[] = $ext('mbstring', 'mbstring extension', self::FAIL, 'Enable the "mbstring" extension in your hosting PHP settings, or ask your host.');
        $c[] = function_exists('json_encode')
            ? ['JSON support', self::PASS, 'Available', '']
            : ['JSON support', self::FAIL, 'Not available', 'Enable the "json" extension, or ask your host.'];
        $c[] = $ext('fileinfo', 'fileinfo extension (product images)', self::WARN, 'Optional. Without it, product image uploads are disabled; everything else works.');
        $c[] = function_exists('gzcompress')
            ? ['zlib (smaller PDF files)', self::PASS, 'Available', '']
            : ['zlib (smaller PDF files)', self::WARN, 'Not available', 'Optional. PDF reports still work but are larger.'];
        $c[] = function_exists('password_hash') && defined('PASSWORD_DEFAULT')
            ? ['Password hashing', self::PASS, 'Available', '']
            : ['Password hashing', self::FAIL, 'Not available', 'Your PHP build lacks password_hash(); ask your host for a standard PHP build.'];
        $c[] = function_exists('random_bytes')
            ? ['Secure random numbers', self::PASS, 'Available', '']
            : ['Secure random numbers', self::FAIL, 'Not available', 'Ask your host for a standard PHP 8 build.'];
        $c[] = session_status() === PHP_SESSION_ACTIVE
            ? ['PHP sessions', self::PASS, 'Working', '']
            : ['PHP sessions', self::FAIL, 'Sessions could not be started', 'Make sure cookies are enabled in your browser and the storage/ folder is writable.'];

        $missing = array_values(array_filter(self::REQUIRED_FILES, static fn ($f) => !is_file(MOTO_ROOT . '/' . $f)));
        $c[] = $missing === []
            ? ['Application files', self::PASS, 'All present', '']
            : ['Application files', self::FAIL, 'Missing: ' . implode(', ', array_slice($missing, 0, 4)) . (count($missing) > 4 ? '…' : ''),
                'Some files did not upload. Upload the ZIP again and extract it, making sure hidden files such as .htaccess are included.'];
        $ht = ['.htaccess', 'app/.htaccess', 'config/.htaccess', 'storage/.htaccess', 'database/.htaccess', 'uploads/.htaccess'];
        $missingHt = array_values(array_filter($ht, static fn ($f) => !is_file(MOTO_ROOT . '/' . $f)));
        $c[] = $missingHt === []
            ? ['Security rules (.htaccess)', self::PASS, 'Present', '']
            : ['Security rules (.htaccess)', self::FAIL, 'Missing: ' . implode(', ', $missingHt),
                'Hidden .htaccess files were not uploaded. Use the File Manager\'s Extract function, or enable "show hidden files" in your FTP program and upload them.'];

        $dirs = $installing ? ['config' => self::FAIL] : [];
        $dirs += ['storage' => self::FAIL, 'storage/logs' => self::WARN, 'storage/sessions' => self::WARN, 'uploads/products' => self::WARN];
        foreach ($dirs as $dir => $level) {
            $path = MOTO_ROOT . '/' . $dir;
            if (is_dir($path) && is_writable($path)) {
                $c[] = ["Folder $dir/", self::PASS, 'Writable', ''];
            } else {
                $c[] = ["Folder $dir/", $level, is_dir($path) ? 'Not writable' : 'Missing',
                    "In the File Manager, make sure the $dir folder exists and set its permissions to 755 (or 775 if 755 does not work)."];
            }
        }

        $c[] = Http::isHttps()
            ? ['HTTPS (SSL)', self::PASS, 'Active', '']
            : ['HTTPS (SSL)', self::WARN, 'Not active', 'Fine for testing. Before real use, install the free SSL certificate in your hosting panel and open the site with https://.'];
        return $c;
    }

    public static function hasFailures(array $checks): bool
    {
        return in_array(self::FAIL, array_column($checks, 1), true);
    }
}
