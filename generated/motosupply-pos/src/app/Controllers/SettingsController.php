<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Http;
use App\Core\Settings;
use App\Services\Migrator;
use App\Services\Requirements;

final class SettingsController extends Controller
{
    public const CURRENCIES = [
        'PHP' => ['Philippine Peso (PHP)', '₱'],
        'USD' => ['US Dollar (USD)', '$'],
    ];

    public function index(): void
    {
        [$old, $errors] = $this->takeOld();
        $this->view('pages/settings', [
            'title' => 'Settings',
            'nav' => 'settings',
            's' => $old ?: Settings::all(),
            'errors' => $errors,
            'pwError' => $_SESSION['_pw_error'] ?? null,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'currencies' => self::CURRENCIES,
        ]);
        unset($_SESSION['_pw_error']);
    }

    public function save(): void
    {
        $in = [
            'shop_name' => Http::post('shop_name'),
            'shop_address' => Http::post('shop_address'),
            'shop_phone' => Http::post('shop_phone'),
            'shop_email' => Http::post('shop_email'),
            'receipt_footer' => Http::post('receipt_footer'),
            'currency_code' => Http::post('currency_code'),
            'timezone' => Http::post('timezone'),
            'low_stock_threshold' => Http::post('low_stock_threshold'),
            'pos_auto_add_barcode' => Http::post('pos_auto_add_barcode') === '1' ? '1' : '0',
            'pos_confirm_clear' => Http::post('pos_confirm_clear') === '1' ? '1' : '0',
            'show_low_stock_badge' => Http::post('show_low_stock_badge') === '1' ? '1' : '0',
        ];
        $errors = [];
        if ($in['shop_name'] === '' || mb_strlen($in['shop_name']) > 100) {
            $errors['shop_name'] = 'Enter the shop name (up to 100 characters).';
        }
        if (mb_strlen($in['shop_address']) > 255) {
            $errors['shop_address'] = 'Address is limited to 255 characters.';
        }
        if (mb_strlen($in['shop_phone']) > 50) {
            $errors['shop_phone'] = 'Phone is limited to 50 characters.';
        }
        if ($in['shop_email'] !== '' && (!filter_var($in['shop_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($in['shop_email']) > 100)) {
            $errors['shop_email'] = 'Enter a valid email address or leave it blank.';
        }
        if (mb_strlen($in['receipt_footer']) > 200) {
            $errors['receipt_footer'] = 'Receipt footer is limited to 200 characters.';
        }
        if (!isset(self::CURRENCIES[$in['currency_code']])) {
            $errors['currency_code'] = 'Choose a currency.';
        }
        if (!in_array($in['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a valid timezone.';
        }
        if (!preg_match('/^\d{1,6}$/', $in['low_stock_threshold'])) {
            $errors['low_stock_threshold'] = 'Enter a whole number (0 or more).';
        }
        if ($errors) {
            $this->withOld($in, $errors);
            Http::flash('error', 'Settings were not saved. Please correct the highlighted fields.');
            Http::redirect(url('settings'));
        }
        $in['currency_symbol'] = self::CURRENCIES[$in['currency_code']][1];
        $in['low_stock_threshold'] = (string) (int) $in['low_stock_threshold'];
        Settings::set($in);
        Http::flash('success', 'Settings saved.');
        Http::redirect(url('settings'));
    }

    public function password(): void
    {
        $user = Auth::user();
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $error = null;
        if (!Auth::verifyCurrentPassword($current)) {
            $error = 'Your current password is incorrect.';
        } elseif (hash_equals($current, $new)) {
            $error = 'The new password must be different from the current password.';
        } else {
            $error = Auth::validateNewPassword($new, $confirm, (string) $user['username']);
        }
        if ($error !== null) {
            $_SESSION['_pw_error'] = $error;
            Http::redirect(url('settings') . '#security');
        }
        Auth::changePassword((int) $user['id'], $new);
        Http::flash('success', 'Your password has been changed.');
        Http::redirect(url('settings'));
    }

    /** Environment diagnostics, available only to logged-in administrators. */
    public function system(): void
    {
        $checks = Requirements::check(false);
        try {
            $dbVersion = (string) DB::value('SELECT VERSION()');
            $checks[] = ['name' => 'Database connection', 'result' => 'Connected (server ' . $dbVersion . ')', 'status' => Requirements::OK, 'explain' => '', 'action' => ''];
        } catch (\Throwable) {
            $checks[] = ['name' => 'Database connection', 'result' => 'Connection failed', 'status' => Requirements::FAIL, 'explain' => 'The application cannot reach its database.', 'action' => 'Check that the database still exists in your hosting panel.'];
        }
        // Prove that application logging works (or falls back safely).
        $logged = \App\Core\Logger::info('System check run by ' . Auth::user()['username']);
        $checks[] = $logged
            ? ['name' => 'Application log', 'result' => 'Written to storage/logs/ (private)', 'status' => Requirements::OK, 'explain' => '', 'action' => '']
            : ['name' => 'Application log', 'result' => 'Using the hosting provider\'s PHP error log', 'status' => Requirements::WARN, 'explain' => 'storage/logs/ is not writable, so errors go to the server\'s own private error log. Nothing else is affected.', 'action' => 'Fix the storage/logs/ folder permissions (see above), then reload this page.'];
        $_SESSION['_session_probe'] = ($_SESSION['_session_probe'] ?? 0) + 1;
        $checks[] = ['name' => 'Session persistence', 'result' => 'Probe count ' . (int) $_SESSION['_session_probe'] . ' (reload: it should increase)', 'status' => Requirements::OK, 'explain' => '', 'action' => ''];
        $this->view('pages/system', [
            'title' => 'System check',
            'nav' => 'settings',
            'checks' => $checks,
            'pending' => Migrator::pending(),
            'https' => Http::isHttps(),
            'mode' => Settings::get('security_mode', 'testing'),
            'override' => \App\Core\Config::get('app.force_https'),
            'proxyHint' => Http::untrustedProxySaysHttps(),
        ]);
    }

    /** Switch between testing mode (HTTP allowed with a warning) and production (HTTPS required). */
    public function security(): void
    {
        $mode = Http::post('mode');
        if ($mode === 'production') {
            if (!Http::isHttps()) {
                Http::flash('error', 'Open this page with https:// first. HTTPS can only be required once a secure connection is confirmed, so you cannot lock yourself out.');
                Http::redirect(url('settings.system') . '#https');
            }
            Settings::set(['security_mode' => 'production']);
            Http::flash('success', 'HTTPS is now required. Visitors using http:// are redirected to https://.');
        } elseif ($mode === 'testing') {
            Settings::set(['security_mode' => 'testing']);
            Http::flash('success', 'Testing mode enabled: plain HTTP is allowed again (with a warning).');
        }
        Http::redirect(url('settings.system') . '#https');
    }

    public function migrate(): void
    {
        $ran = (new Migrator(DB::pdo()))->migrate();
        Http::flash('success', $ran ? 'Applied database updates: ' . implode(', ', $ran) . '.' : 'The database is already up to date.');
        Http::redirect(url('settings.system'));
    }
}
