<?php
declare(strict_types=1);

defined('MOTO_ROOT') || exit;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;
use App\Services\Migrator;

/** Installation logic for the browser wizard. Messages returned here are safe to show to visitors. */
final class Installer
{
    public const LOCK_FILE = MOTO_ROOT . '/storage/installed.lock';
    public const APP_TABLES = ['users', 'settings', 'categories', 'products', 'sales', 'sale_items', 'stock_movements', 'login_attempts', 'schema_migrations'];
    /** Where the last install stored the configuration: 'private' (outside the web root) or 'webroot'. */
    public static string $configLocation = '';

    public const CURRENCIES = ['PHP' => ['Philippine Peso (PHP)', '₱'], 'USD' => ['US Dollar (USD)', '$']];

    /** True once installation finished: never run the installer again. */
    public static function isInstalled(): bool
    {
        return is_file(self::LOCK_FILE) || Config::exists();
    }

    // ---------------------------------------------------------------- validation

    /** @return array{0:array,1:array<string,string>} [clean values, errors] */
    public static function validateDatabase(array $in, ?string $keepPassword): array
    {
        $e = [];
        $host = trim((string) ($in['db_host'] ?? ''));
        $port = trim((string) ($in['db_port'] ?? ''));
        if (preg_match('/^(.+):(\d{1,5})$/', $host, $m)) { // accept "host:port"
            [$host, $port] = [$m[1], $port === '' || $port === '3306' ? $m[2] : $port];
        }
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-_]{1,255}$/', $host)) {
            $e['db_host'] = 'Enter the database host exactly as shown in your hosting control panel (for example sql123.infinityfree.com or localhost).';
        }
        $port = $port === '' ? '3306' : $port;
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $e['db_port'] = 'The port must be a number (usually 3306).';
        }
        $name = trim((string) ($in['db_name'] ?? ''));
        if ($name === '' || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name)) {
            $e['db_name'] = 'Enter the database name exactly as shown in your hosting control panel (letters, numbers and underscores).';
        }
        $user = trim((string) ($in['db_user'] ?? ''));
        if ($user === '' || strlen($user) > 80 || preg_match('/[\x00-\x1F]/', $user)) {
            $e['db_user'] = 'Enter the database username from your hosting control panel.';
        }
        $pass = is_string($in['db_pass'] ?? null) ? $in['db_pass'] : '';
        if ($pass === '' && $keepPassword !== null) {
            $pass = $keepPassword; // password already entered earlier in this wizard
        }
        if (strlen($pass) > 200) {
            $e['db_pass'] = 'The database password is too long.';
        }
        return [['host' => $host, 'port' => (int) $port, 'name' => $name, 'user' => $user, 'pass' => $pass], $e];
    }

    public static function validateShop(array $in): array
    {
        $e = [];
        $v = [
            'shop_name' => trim((string) ($in['shop_name'] ?? '')),
            'shop_address' => trim((string) ($in['shop_address'] ?? '')),
            'shop_phone' => trim((string) ($in['shop_phone'] ?? '')),
            'timezone' => trim((string) ($in['timezone'] ?? 'Asia/Manila')),
            'currency_code' => trim((string) ($in['currency_code'] ?? 'PHP')),
        ];
        if ($v['shop_name'] === '' || mb_strlen($v['shop_name']) > 100) {
            $e['shop_name'] = 'Enter the shop name (up to 100 characters).';
        }
        if (mb_strlen($v['shop_address']) > 255) {
            $e['shop_address'] = 'The address is limited to 255 characters.';
        }
        if (mb_strlen($v['shop_phone']) > 50) {
            $e['shop_phone'] = 'The contact number is limited to 50 characters.';
        }
        if (!in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $e['timezone'] = 'Choose a timezone from the list.';
        }
        if (!isset(self::CURRENCIES[$v['currency_code']])) {
            $e['currency_code'] = 'Choose a currency from the list.';
        }
        return [$v, $e];
    }

    public static function validateAdmin(array $in): array
    {
        $e = [];
        $username = trim((string) ($in['admin_user'] ?? ''));
        $name = trim((string) ($in['admin_name'] ?? ''));
        $pw = is_string($in['admin_pass'] ?? null) ? $in['admin_pass'] : '';
        $pw2 = is_string($in['admin_pass2'] ?? null) ? $in['admin_pass2'] : '';
        if (!preg_match('/^[A-Za-z0-9._\-]{3,50}$/', $username)) {
            $e['admin_user'] = 'Username: 3 to 50 characters using letters, numbers, dots, dashes or underscores.';
        }
        if (mb_strlen($name) > 100) {
            $e['admin_name'] = 'The name is limited to 100 characters.';
        }
        $pwErr = Auth::passwordStrengthError($pw, $username);
        if ($pwErr !== null) {
            $e['admin_pass'] = $pwErr;
        } elseif (!hash_equals($pw, $pw2)) {
            $e['admin_pass2'] = 'The passwords do not match.';
        }
        // Only the hash is kept between wizard steps; the plaintext password is discarded.
        return [['username' => $username, 'full_name' => $name, 'hash' => $e ? '' : password_hash($pw, PASSWORD_DEFAULT)], $e];
    }

    // ---------------------------------------------------------------- database

    /** Connect, returning [PDO|null, user-safe error message|null]. Never exposes raw exceptions. */
    public static function connect(array $db): array
    {
        try {
            return [DB::connect($db), null];
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
                $code = (int) $m[1];
            }
            Logger::error('Installer database connection failed (MySQL error ' . $code . ')');
            $msg = match ($code) {
                1045 => 'The database username or password is incorrect. Copy them again from your hosting control panel. (On InfinityFree the database password is your hosting account password.)',
                1044 => 'This database user is not allowed to use that database. In your hosting panel, add the user to the database with all privileges.',
                1049 => 'A database with that name was not found. Check the database name in your hosting control panel (it often has a prefix such as if0_12345678_).',
                2002, 2003, 2005, 2006 => 'Could not reach the database server. Check the Database host. Use the hostname shown in your hosting panel (on InfinityFree it looks like sqlXXX.infinityfree.com, not localhost).',
                default => 'Could not connect to the database. Check all four database values in your hosting control panel and try again.',
            };
            return [null, $msg];
        }
    }

    /**
     * Inspect the target database.
     * @return array{state:string,message:string,ok:bool}
     *   state: empty | other_tables | partial | installed | conflict
     */
    public static function inspect(PDO $pdo): array
    {
        $tables = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $ours = array_values(array_intersect(self::APP_TABLES, $tables));
        if ($ours === []) {
            return $tables === []
                ? ['state' => 'empty', 'ok' => true, 'message' => 'Connection successful. The database is empty and ready for installation.']
                : ['state' => 'other_tables', 'ok' => true, 'message' => 'Connection successful. The database contains other tables; MotoSupply will add its own tables and will not change the existing ones.'];
        }
        if (!in_array('schema_migrations', $tables, true)) {
            return ['state' => 'conflict', 'ok' => false, 'message' => 'This database already contains tables with the same names as MotoSupply tables (for example "' . $ours[0] . '") from another application. Create a new, empty database for MotoSupply and use that instead.'];
        }
        $users = in_array('users', $tables, true) ? (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;
        if ($users > 0) {
            return ['state' => 'installed', 'ok' => false, 'message' => 'This database already contains a MotoSupply installation with an administrator account. The installer will not overwrite it or create another administrator. To reconnect to it, restore your configuration backup; to start fresh, use a new empty database.'];
        }
        return ['state' => 'partial', 'ok' => true, 'message' => 'Connection successful. An earlier installation attempt was not finished; the installer will safely complete it.'];
    }

    public static function privileges(PDO $pdo): ?string
    {
        // Try creating and dropping a scratch table to confirm CREATE/DROP privileges.
        $t = 'motosupply_install_probe_' . bin2hex(random_bytes(3));
        try {
            $pdo->exec("CREATE TABLE `$t` (id INT PRIMARY KEY) ENGINE=InnoDB");
            $pdo->exec("DROP TABLE `$t`");
            return null;
        } catch (PDOException) {
            return 'The database user cannot create tables. In your hosting panel, give the user ALL PRIVILEGES on this database.';
        }
    }

    // ---------------------------------------------------------------- install

    /**
     * Run the installation. Returns null on success or a user-safe error message.
     * Steps that can be undone are undone on failure so the wizard can be retried.
     */
    public static function install(array $db, array $shop, array $admin, string $securityMode = 'testing'): ?string
    {
        $lock = @fopen(MOTO_ROOT . '/storage/install.lock.tmp', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'Another installation is already running. Wait a minute, then reload this page.';
        }
        try {
            if (self::isInstalled()) {
                return 'MotoSupply is already installed. The installer is locked.';
            }
            // 1–2. Revalidate and verify connectivity.
            [, $e1] = self::validateDatabase(['db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass']], null);
            [, $e2] = self::validateShop($shop);
            if ($e1 || $e2 || $admin['hash'] === '' || !preg_match('/^[A-Za-z0-9._\-]{3,50}$/', $admin['username'])) {
                return 'Some information is missing or invalid. Go back through the steps and check each one.';
            }
            if (\App\Services\Requirements::hasFailures(\App\Services\Requirements::check(true))) {
                return 'A server requirement is no longer met (for example a folder became unwritable). Go back to the Requirements step, follow the instructions and click Recheck Requirements.';
            }
            [$pdo, $err] = self::connect($db);
            if ($pdo === null) {
                return $err;
            }
            // 3. Safe to create/migrate?
            $state = self::inspect($pdo);
            if (!$state['ok']) {
                return $state['message'];
            }
            if ($p = self::privileges($pdo)) {
                return $p;
            }
            DB::setPdo($pdo);
            Settings::reset();

            // 4–5. Tables and migrations (idempotent: CREATE TABLE IF NOT EXISTS + schema_migrations).
            try {
                (new Migrator($pdo))->migrate();
            } catch (Throwable $e) {
                Logger::error('Installer migration failed', $e);
                return 'The database tables could not be created. Make sure the database user has ALL PRIVILEGES, then click Install again. (Tables that were already created are reused safely.)';
            }

            // 7–8. Administrator and shop settings in one transaction.
            $now = Clock::nowUtc();
            try {
                DB::transaction(static function () use ($pdo, $shop, $admin, $now, $securityMode): void {
                    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                        throw new RuntimeException('users exist');
                    }
                    Settings::set([
                        'shop_name' => $shop['shop_name'],
                        'shop_address' => $shop['shop_address'],
                        'shop_phone' => $shop['shop_phone'],
                        'timezone' => $shop['timezone'],
                        'currency_code' => $shop['currency_code'],
                        'currency_symbol' => self::CURRENCIES[$shop['currency_code']][1],
                        'installed_at' => $now,
                        // production = HTTPS required; testing = installed over HTTP, warning shown.
                        'security_mode' => $securityMode === 'production' ? 'production' : 'testing',
                    ]);
                    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'administrator'")->fetchColumn();
                    $pdo->prepare(
                        'INSERT INTO users (username, password_hash, full_name, role, role_id, must_change_password, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, \'administrator\', ?, 0, 1, ?, ?)'
                    )->execute([$admin['username'], $admin['hash'], $admin['full_name'], $roleId, $now, $now]);
                });
            } catch (Throwable $e) {
                Logger::error('Installer data step failed', $e);
                return $e->getMessage() === 'users exist'
                    ? 'An administrator already exists in this database. The installer will not create another one.'
                    : 'The administrator account could not be created. Nothing was saved; please click Install again.';
            }

            // 6. Configuration.
            $config = [
                'db' => $db,
                'app' => [
                    'debug' => false,
                    // HTTPS enforcement follows Settings → System Check ("Require HTTPS").
                    // Proxies listed here may set X-Forwarded-Proto (see README "HTTPS behind a proxy").
                    'trusted_proxies' => [],
                    'session_idle_seconds' => 1800,
                    'session_absolute_seconds' => 43200,
                    'secret' => bin2hex(random_bytes(32)),
                ],
                'installed_at' => gmdate('c'),
                'version' => MOTO_VERSION,
            ];
            try {
                $where = Config::write($config);
            } catch (Throwable $e) {
                Logger::error('Installer could not write configuration', $e);
                self::undoData($pdo);
                return 'The configuration file could not be saved. Set the config/ folder permissions to 755 (or 775), then click Install again.';
            }

            // 9. Installation state (lock).
            if (@file_put_contents(self::LOCK_FILE, 'Installed ' . gmdate('c') . "\n", LOCK_EX) === false) {
                @unlink(Config::path());
                self::undoData($pdo);
                return 'The installation could not be locked because storage/ is not writable. Fix the folder permissions, then click Install again.';
            }

            // 10. Verify using the saved configuration, exactly as the application will.
            try {
                Config::load();
                $check = DB::connect((array) Config::get('db'));
                $ok = (int) $check->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1
                    && (int) $check->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() >= MOTO_SCHEMA_VERSION;
            } catch (Throwable $e) {
                Logger::error('Installer verification failed', $e);
                $ok = false;
            }
            if (!$ok) {
                return 'Installation finished but could not be verified. Open the login page; if it does not work, see "Installation problems" in README.md.';
            }
            self::$configLocation = $where;
            Logger::info('MotoSupply installed (config stored: ' . $where . ')');
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink(MOTO_ROOT . '/storage/install.lock.tmp');
        }
    }

    /** Remove the rows written by this installer run so the wizard can be retried cleanly. */
    private static function undoData(PDO $pdo): void
    {
        try {
            $pdo->exec('DELETE FROM users');
            $pdo->exec('DELETE FROM settings');
        } catch (Throwable) {
        }
    }
}
