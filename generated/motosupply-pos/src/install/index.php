<?php
declare(strict_types=1);

/*
 * MotoSupply POS — browser installation wizard.
 * Runs once. After a successful installation it writes storage/installed.lock and
 * config/config.php and refuses to run again. Delete this folder after installing.
 */

define('MOTO_ROOT', dirname(__DIR__));
require MOTO_ROOT . '/app/bootstrap.php';

use App\Controllers\SettingsController;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Settings;
use App\Services\Migrator;

const LOCK_FILE = MOTO_ROOT . '/storage/installed.lock';

Config::load([]); // no configuration yet
Http::securityHeaders();
Session::start();

function installed(): bool
{
    return is_file(LOCK_FILE) || Config::exists();
}

function render(string $title, string $body): never
{
    $css = e(asset('css/app.css'));
    $logo = e(asset('img/logo.svg'));
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title} · MotoSupply POS installer</title>
<link rel="icon" href="{$logo}" type="image/svg+xml">
<link rel="stylesheet" href="{$css}">
</head>
<body class="guest">
<main class="guest-main" id="main">
  <div class="guest-brand"><span class="brand-mark" aria-hidden="true">M</span><span class="brand-text"><strong>MOTO<span>SUPPLY</span></strong><small>INSTALLATION</small></span></div>
  {$body}
</main>
</body>
</html>
HTML;
    exit;
}

// ---- Refuse to run on an existing installation -------------------------------------------
if (installed()) {
    http_response_code(403);
    $login = e(Http::basePath() . '/index.php?r=login');
    render('Already installed', <<<HTML
<section class="auth-card">
  <h1>Already installed</h1>
  <p>MotoSupply POS is already installed on this server. The installer is locked and cannot be run again,
  and it will never change or reset existing accounts.</p>
  <p class="muted small">For security, delete the <code>install/</code> folder from your hosting account using the File Manager or FTP.</p>
  <a class="btn btn-primary btn-block" href="{$login}">Go to login</a>
</section>
HTML);
}

$checks = SettingsController::environmentChecks(true);
$required = array_filter($checks, static fn ($c) => !str_contains($c[0], 'optional') && $c[0] !== 'HTTPS');
$envOk = !in_array(false, array_column($required, 1), true);

$v = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'shop_name' => 'MotoSupply Shop', 'timezone' => 'Asia/Manila',
    'admin_user' => 'admin', 'admin_name' => '',
];
$errors = [];
$fatal = null;

if (Http::isPost()) {
    foreach (array_keys($v) as $k) {
        $v[$k] = Http::post($k);
    }
    $dbPass = is_string($_POST['db_pass'] ?? null) ? $_POST['db_pass'] : '';
    $pw = is_string($_POST['admin_pass'] ?? null) ? $_POST['admin_pass'] : '';
    $pw2 = is_string($_POST['admin_pass2'] ?? null) ? $_POST['admin_pass2'] : '';

    if (!Csrf::check()) {
        $fatal = 'The form expired. Please submit it again.';
    } elseif (!$envOk) {
        $fatal = 'The server does not meet the requirements listed below.';
    } else {
        if (!preg_match('/^[A-Za-z0-9.\-_]{1,255}$/', $v['db_host'])) {
            $errors['db_host'] = 'Enter the database hostname from your hosting control panel.';
        }
        if (!preg_match('/^\d{1,5}$/', $v['db_port']) || (int) $v['db_port'] < 1 || (int) $v['db_port'] > 65535) {
            $errors['db_port'] = 'Enter a valid port (usually 3306).';
        }
        if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $v['db_name'])) {
            $errors['db_name'] = 'Enter the database name exactly as shown in your hosting control panel.';
        }
        if ($v['db_user'] === '' || strlen($v['db_user']) > 80 || preg_match('/[\x00-\x1F]/', $v['db_user'])) {
            $errors['db_user'] = 'Enter the database username.';
        }
        if (strlen($dbPass) > 200) {
            $errors['db_pass'] = 'Database password is too long.';
        }
        if ($v['shop_name'] === '' || mb_strlen($v['shop_name']) > 100) {
            $errors['shop_name'] = 'Enter the shop name (up to 100 characters).';
        }
        if (!in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a valid timezone.';
        }
        if (!preg_match('/^[A-Za-z0-9._\-]{3,50}$/', $v['admin_user'])) {
            $errors['admin_user'] = 'Username: 3–50 letters, numbers, dots, dashes or underscores.';
        }
        if (mb_strlen($v['admin_name']) > 100) {
            $errors['admin_name'] = 'Name is limited to 100 characters.';
        }
        // "admin" is accepted only as a temporary password and forces a change at first login.
        $temporary = $pw === 'admin' && $pw2 === 'admin';
        if (!$temporary) {
            $pwErr = Auth::validateNewPassword($pw, $pw2, $v['admin_user']);
            if ($pwErr !== null) {
                $errors['admin_pass'] = $pwErr;
            }
        }

        if (!$errors) {
            $lock = @fopen(MOTO_ROOT . '/storage/install.lock.tmp', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                $fatal = 'Another installation is in progress. Wait a moment and reload this page.';
            } elseif (installed()) {
                $fatal = 'MotoSupply POS was installed by another request. Reload this page.';
            } else {
                $db = ['host' => $v['db_host'], 'port' => (int) $v['db_port'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $dbPass];
                try {
                    $pdo = DB::connect($db);
                } catch (PDOException $e) {
                    Logger::error('Installer database connection failed (code ' . $e->getCode() . ')');
                    $pdo = null;
                    $fatal = 'Could not connect to the database. Check the hostname, database name, username and password '
                        . 'from your hosting control panel. (On InfinityFree the hostname looks like sqlXXX.infinityfree.com, not localhost.)';
                }
                if ($pdo !== null) {
                    try {
                        $existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
                        if ($existing !== false && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                            $fatal = 'This database already contains a MotoSupply installation with user accounts. '
                                . 'The installer will not overwrite it or create another administrator. Use an empty database, '
                                . 'or restore config/config.php from your backup.';
                        } else {
                            DB::setPdo($pdo);
                            (new Migrator($pdo))->migrate();
                            Settings::reset();
                            Settings::set([
                                'shop_name' => $v['shop_name'],
                                'timezone' => $v['timezone'],
                                'currency_code' => 'PHP',
                                'currency_symbol' => '₱',
                            ]);
                            $now = Clock::nowUtc();
                            $pdo->prepare(
                                'INSERT INTO users (username, password_hash, full_name, role, must_change_password, is_active, created_at, updated_at)
                                 VALUES (?, ?, ?, \'admin\', ?, 1, ?, ?)'
                            )->execute([$v['admin_user'], password_hash($pw, PASSWORD_DEFAULT), $v['admin_name'], $temporary ? 1 : 0, $now, $now]);

                            $config = [
                                'db' => $db,
                                'app' => [
                                    'debug' => false,
                                    'force_https' => false,
                                    'session_idle_seconds' => 1800,
                                    'session_absolute_seconds' => 43200,
                                    'secret' => bin2hex(random_bytes(32)),
                                ],
                                'installed_at' => gmdate('c'),
                                'version' => MOTO_VERSION,
                            ];
                            $path = Config::path();
                            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
                            if (@file_put_contents($tmp, Config::export($config), LOCK_EX) === false || !@rename($tmp, $path)) {
                                @unlink($tmp);
                                throw new RuntimeException('config not writable');
                            }
                            @chmod($path, 0640);
                            file_put_contents(LOCK_FILE, 'Installed ' . gmdate('c') . "\n", LOCK_EX);

                            // Verify.
                            Config::load();
                            $verifyPdo = DB::connect((array) Config::get('db'));
                            $count = (int) $verifyPdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                            if ($count < 1) {
                                throw new RuntimeException('verification failed');
                            }
                            Session::destroy();
                            flock($lock, LOCK_UN);
                            fclose($lock);
                            @unlink(MOTO_ROOT . '/storage/install.lock.tmp');
                            $login = e(Http::basePath() . '/index.php?r=login');
                            $note = $temporary
                                ? '<p class="alert alert-info"><span>You chose the temporary password. You will be asked to set a new password when you first log in.</span></p>'
                                : '';
                            render('Installed', <<<HTML
<section class="auth-card">
  <h1>Installation complete</h1>
  <p>The database tables, shop settings and administrator account were created, and the installer is now locked.</p>
  {$note}
  <div class="alert alert-error"><span><strong>Important:</strong> delete the <code>install/</code> folder from your hosting account now (File Manager or FTP).</span></div>
  <a class="btn btn-primary btn-block" href="{$login}">Go to login</a>
</section>
HTML);
                        }
                    } catch (Throwable $e) {
                        Logger::error('Installer failed', $e);
                        $fatal = $e instanceof RuntimeException && $e->getMessage() === 'config not writable'
                            ? 'The database was prepared, but config/config.php could not be written. Make sure the config/ folder is writable, then empty the database tables and run the installer again.'
                            : 'Installation failed while creating the database tables. Check that the database user has CREATE, ALTER, INDEX and REFERENCES privileges.';
                    }
                }
            }
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink(MOTO_ROOT . '/storage/install.lock.tmp');
            }
        }
    }
}

// ---- Form -------------------------------------------------------------------------------
$rows = '';
foreach ($checks as [$label, $ok, $detail]) {
    $rows .= '<tr><td>' . e($label) . '</td><td>' . ($ok ? '<span class="pill pill-success">OK</span>' : '<span class="pill pill-warning">Check</span>')
        . '</td><td class="small">' . e($detail) . '</td></tr>';
}
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$val = static fn (string $k): string => e($v[$k]);
$tzOptions = '';
foreach (DateTimeZone::listIdentifiers() as $tz) {
    $tzOptions .= '<option value="' . e($tz) . '"' . ($tz === $v['timezone'] ? ' selected' : '') . '>' . e($tz) . '</option>';
}
$csrf = csrf_field();
$fatalHtml = $fatal ? '<div class="alert alert-error" role="alert"><span>' . e($fatal) . '</span></div>' : '';
$envHtml = $envOk ? '' : '<div class="alert alert-error" role="alert"><span>This server is missing a required PHP feature. Fix the items marked “Check” before installing.</span></div>';
$disabled = $envOk ? '' : ' disabled';
$https = Http::isHttps() ? '' : '<p class="alert alert-info"><span>This page is not using HTTPS. Enable the free SSL certificate in your hosting panel before using the system in production.</span></p>';

render('Install', <<<HTML
<section class="auth-card install-card">
  <h1>Install MotoSupply POS</h1>
  <p class="muted">Create a MySQL database in your hosting control panel first, then enter its details below.</p>
  {$fatalHtml}{$envHtml}{$https}
  <details class="card" open>
    <summary class="card-pad"><strong>1. Server requirements</strong></summary>
    <div class="table-wrap"><table class="table"><tbody>{$rows}</tbody></table></div>
  </details>
  <form method="post" action="" class="stack" novalidate data-once>
    {$csrf}
    <fieldset class="stack">
      <legend><strong>2. Database</strong></legend>
      <div class="form-grid">
        <div class="field"><label for="db_host">Database host</label><input id="db_host" name="db_host" required value="{$val('db_host')}"{$inv('db_host')}>{$err('db_host')}</div>
        <div class="field"><label for="db_port">Port</label><input id="db_port" name="db_port" inputmode="numeric" required value="{$val('db_port')}"{$inv('db_port')}>{$err('db_port')}</div>
        <div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" required value="{$val('db_name')}" placeholder="e.g. if0_12345678_motosupply"{$inv('db_name')}>{$err('db_name')}</div>
        <div class="field"><label for="db_user">Database username</label><input id="db_user" name="db_user" required autocomplete="off" value="{$val('db_user')}" placeholder="e.g. if0_12345678"{$inv('db_user')}>{$err('db_user')}</div>
        <div class="field span-2"><label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="new-password"{$inv('db_pass')}>{$err('db_pass')}</div>
      </div>
    </fieldset>
    <fieldset class="stack">
      <legend><strong>3. Shop</strong></legend>
      <div class="form-grid">
        <div class="field"><label for="shop_name">Shop name</label><input id="shop_name" name="shop_name" required maxlength="100" value="{$val('shop_name')}"{$inv('shop_name')}>{$err('shop_name')}</div>
        <div class="field"><label for="timezone">Timezone</label><select id="timezone" name="timezone"{$inv('timezone')}>{$tzOptions}</select>{$err('timezone')}</div>
      </div>
    </fieldset>
    <fieldset class="stack">
      <legend><strong>4. Administrator account</strong></legend>
      <div class="form-grid">
        <div class="field"><label for="admin_user">Username</label><input id="admin_user" name="admin_user" required maxlength="50" autocomplete="username" value="{$val('admin_user')}"{$inv('admin_user')}>{$err('admin_user')}</div>
        <div class="field"><label for="admin_name">Full name (optional)</label><input id="admin_name" name="admin_name" maxlength="100" value="{$val('admin_name')}"{$inv('admin_name')}>{$err('admin_name')}</div>
        <div class="field"><label for="admin_pass">Password</label><input id="admin_pass" name="admin_pass" type="password" required maxlength="72" autocomplete="new-password" aria-describedby="pw-hint"{$inv('admin_pass')}>{$err('admin_pass')}</div>
        <div class="field"><label for="admin_pass2">Confirm password</label><input id="admin_pass2" name="admin_pass2" type="password" required maxlength="72" autocomplete="new-password"></div>
      </div>
      <small class="hint" id="pw-hint">Use at least 8 characters. For a quick test install you may enter the temporary password <code>admin</code>; you will then be forced to change it at first login.</small>
    </fieldset>
    <button type="submit" class="btn btn-primary btn-block"{$disabled}>Install</button>
  </form>
</section>
HTML);
