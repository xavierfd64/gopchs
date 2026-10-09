<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Http;
use App\Core\Settings;
use App\Core\Secrets;
use App\Core\ValidationException;
use App\Services\Branding;
use App\Services\EmailReports;
use App\Services\Mailer;
use App\Services\Migrator;
use App\Services\Theme;
use App\Services\Requirements;

final class SettingsController extends Controller
{
    public const CURRENCIES = [
        'PHP' => ['Philippine Peso (PHP)', '₱'],
        'USD' => ['US Dollar (USD)', '$'],
    ];

    /** Settings tabs the current user may open: route => [label, permission, icon]. */
    public static function tabs(): array
    {
        $all = [
            'settings' => ['Store', 'settings.manage', 'store'],
            'settings.receipt' => ['Receipt & printing', 'settings.notifications', 'printer'],
            'settings.email' => ['Email reports', 'settings.notifications', 'mail'],
            'settings.appearance' => ['Appearance', 'settings.manage', 'palette'],
            'users' => ['Users & permissions', 'users.manage', 'users'],
            'audit' => ['Audit log', 'audit.view', 'history'],
            'settings.system' => ['System check', 'settings.manage', 'settings'],
            'updates' => ['Updates', 'system.update', 'download'],
        ];
        return array_filter($all, static fn ($t) => Auth::can($t[1]));
    }

    public function index(): void
    {
        if (!Auth::can('settings.manage')) {
            Http::redirect(url((string) array_key_first(self::tabs())));
        }
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
        $this->saveAudited('settings.store', $in);
        Http::flash('success', 'Settings saved.');
        Http::redirect(url('settings'));
    }

    /** Save settings and write an audit record listing which keys changed (never secret values). */
    private function saveAudited(string $action, array $values, array $secretKeys = []): void
    {
        $before = Settings::all();
        $changed = [];
        foreach ($values as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) $v) {
                $changed[$k] = in_array($k, $secretKeys, true) ? ['changed' => true] : ['from' => $before[$k] ?? null, 'to' => $v];
            }
        }
        Settings::set($values);
        Audit::log($action, 'settings', '', ['changes' => $changed]);
    }

    public function receipt(): void
    {
        if (Http::isPost()) {
            $paper = Http::post('receipt_paper');
            $this->saveAudited('settings.receipt', [
                'receipt_auto_print' => Http::post('receipt_auto_print') === '1' ? '1' : '0',
                'receipt_paper' => in_array($paper, ['58mm', '80mm', 'a4'], true) ? $paper : '80mm',
                'receipt_show_logo' => Http::post('receipt_show_logo') === '1' ? '1' : '0',
            ]);
            Http::flash('success', 'Receipt printing settings saved.');
            Http::redirect(url('settings.receipt'));
        }
        $this->view('pages/settings_receipt', ['title' => 'Receipt & printing', 'nav' => 'settings', 's' => Settings::all()]);
    }

    public function email(): void
    {
        $errors = [];
        $s = Settings::all();
        if (Http::isPost()) {
            $in = [
                'email_enabled' => Http::post('email_enabled') === '1' ? '1' : '0',
                'email_recipients' => Http::post('email_recipients'),
                'email_time' => Http::post('email_time'),
                'email_timezone' => Http::post('email_timezone'),
                'email_sections' => implode(',', array_values(array_intersect(array_keys(EmailReports::SECTIONS), (array) ($_POST['email_sections'] ?? [])))),
                'email_attach_pdf' => Http::post('email_attach_pdf') === '1' ? '1' : '0',
                'email_attach_csv' => Http::post('email_attach_csv') === '1' ? '1' : '0',
                'email_on_visit' => Http::post('email_on_visit') === '1' ? '1' : '0',
                'mail_transport' => Http::post('mail_transport') === 'phpmail' ? 'phpmail' : 'smtp',
                'mail_from_address' => Http::post('mail_from_address'),
                'mail_from_name' => Http::post('mail_from_name'),
                'smtp_host' => Http::post('smtp_host'),
                'smtp_port' => Http::post('smtp_port'),
                'smtp_encryption' => Http::post('smtp_encryption'),
                'smtp_username' => Http::post('smtp_username'),
            ];
            $list = array_filter(array_map('trim', explode(',', str_replace([';', "\n"], ',', $in['email_recipients']))));
            foreach ($list as $a) {
                if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
                    $errors['email_recipients'] = '"' . $a . '" is not a valid email address.';
                }
            }
            if (count($list) > 10) {
                $errors['email_recipients'] = 'Up to 10 recipients.';
            }
            if ($in['email_enabled'] === '1' && $list === []) {
                $errors['email_recipients'] = 'Add at least one recipient to enable the daily report.';
            }
            $in['email_recipients'] = implode(', ', $list);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['email_time'])) {
                $errors['email_time'] = 'Enter a time like 06:00.';
            }
            if ($in['email_timezone'] !== '' && !in_array($in['email_timezone'], \DateTimeZone::listIdentifiers(), true)) {
                $errors['email_timezone'] = 'Choose a valid timezone.';
            }
            if ($in['mail_from_address'] !== '' && !filter_var($in['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
                $errors['mail_from_address'] = 'Enter a valid email address.';
            }
            if (mb_strlen($in['mail_from_name']) > 100 || preg_match('/[\r\n]/', $in['mail_from_name'])) {
                $errors['mail_from_name'] = 'Enter a short name (one line).';
            }
            if ($in['smtp_host'] !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,255}$/', $in['smtp_host'])) {
                $errors['smtp_host'] = 'Enter a host name such as smtp.gmail.com.';
            }
            if (!preg_match('/^\d{1,5}$/', $in['smtp_port']) || (int) $in['smtp_port'] < 1 || (int) $in['smtp_port'] > 65535) {
                $errors['smtp_port'] = 'Enter a port such as 587.';
            }
            if (!in_array($in['smtp_encryption'], ['tls', 'ssl', 'none'], true)) {
                $errors['smtp_encryption'] = 'Choose an encryption type.';
            }
            if (mb_strlen($in['smtp_username']) > 200) {
                $errors['smtp_username'] = 'Too long.';
            }
            $pw = is_string($_POST['smtp_password'] ?? null) ? $_POST['smtp_password'] : '';
            if (strlen($pw) > 500) {
                $errors['smtp_password'] = 'Too long.';
            }
            if (!$errors) {
                if (Http::post('smtp_password_clear') === '1') {
                    $in['smtp_password_enc'] = '';
                } elseif ($pw !== '') {
                    $in['smtp_password_enc'] = Secrets::encrypt($pw);
                }
                $this->saveAudited('settings.email', $in, ['smtp_password_enc']);
                Http::flash('success', 'Email report settings saved.');
                Http::redirect(url('settings.email'));
            }
            $s = array_merge($s, $in);
        }
        $this->view('pages/settings_email', [
            'title' => 'Email reports',
            'nav' => 'settings',
            's' => $s,
            'errors' => $errors,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'runs' => EmailReports::recentRuns(),
            'cronKey' => $_SESSION['_cron_key'] ?? null,
            'https' => Http::isHttps(),
        ]);
        unset($_SESSION['_cron_key']);
    }

    public function emailTest(): void
    {
        try {
            $msg = EmailReports::compose(EmailReports::build(\App\Core\Clock::withTimezone(EmailReports::timezone(), static fn () => \App\Core\Clock::todayLocal())), true);
            Mailer::send(EmailReports::recipients(), $msg['subject'], $msg['text'], $msg['html'], $msg['attachments']);
            Audit::log('email.test', 'email', '', ['recipients' => count(EmailReports::recipients())]);
            Http::flash('success', 'Test email sent to ' . implode(', ', EmailReports::recipients()) . '. Check the inbox (and spam folder).');
        } catch (\Throwable $e) {
            $msg = $e instanceof \RuntimeException ? $e->getMessage() : 'Unexpected error while sending.';
            Audit::log('email.test', 'email', '', ['error' => $msg], 'failure');
            Http::flash('error', 'Test email failed: ' . $msg);
        }
        Http::redirect(url('settings.email'));
    }

    /** Send (or resend after a failure) the report for a chosen past date. Never sends a date twice. */
    public function emailSendNow(): void
    {
        $date = Http::post('date');
        $today = \App\Core\Clock::withTimezone(EmailReports::timezone(), static fn () => \App\Core\Clock::todayLocal());
        if (!\App\Core\Clock::isDate($date) || $date >= $today) {
            Http::flash('error', 'Choose a completed day (yesterday or earlier).');
        } else {
            $r = EmailReports::runScheduled('manual', $date);
            Audit::log('email.report.manual', 'email_report', $date, ['result' => $r], str_starts_with($r, 'failed') ? 'failure' : 'success');
            Http::flash(str_starts_with($r, 'failed') ? 'error' : 'success', match ($r) {
                'sent' => "Report for $date sent.",
                'already-sent' => "The report for $date was already sent; it is not sent twice.",
                'busy' => "The report for $date is being sent right now. Check again in a few minutes.",
                default => 'Report failed: ' . substr($r, 8),
            });
        }
        Http::redirect(url('settings.email'));
    }

    /** Generate a new secret for the scheduled-task URL. Only its hash is stored; shown once. */
    public function emailCronKey(): void
    {
        $key = bin2hex(random_bytes(24));
        Settings::set(['email_cron_key_hash' => hash('sha256', $key)]);
        Audit::log('email.cron_key.regenerate', 'settings', 'email_cron_key');
        $_SESSION['_cron_key'] = $key;
        Http::redirect(url('settings.email') . '#schedule');
    }

    public function appearance(): void
    {
        $error = null;
        $s = Settings::all();
        if (Http::isPost()) {
            if (Http::post('reset') === '1') {
                $p = Theme::DEFAULT_PRIMARY;
                $sb = Theme::DEFAULT_SIDEBAR;
            } else {
                $p = strtolower(Http::post('theme_primary'));
                $sb = strtolower(Http::post('theme_sidebar'));
            }
            $error = Theme::validate($p, $sb);
            if ($error === null) {
                $this->saveAudited('settings.theme', ['theme_primary' => $p, 'theme_sidebar' => $sb]);
                Http::flash('success', 'Theme saved.');
                Http::redirect(url('settings.appearance'));
            }
            $s['theme_primary'] = $p;
            $s['theme_sidebar'] = $sb;
        }
        $this->view('pages/settings_appearance', [
            'title' => 'Appearance',
            'nav' => 'settings',
            's' => $s,
            'error' => $error,
            'brandError' => $_SESSION['_brand_error'] ?? null,
            'scripts' => ['js/settings.js'],
        ]);
        unset($_SESSION['_brand_error']);
    }

    public function branding(): void
    {
        $kind = Http::post('kind') === 'favicon' ? 'favicon' : 'logo';
        try {
            if (Http::post('remove') === '1') {
                Branding::remove($kind);
                Audit::log('settings.branding.remove', 'branding', $kind);
                Http::flash('success', ucfirst($kind) . ' removed; the default is used again.');
            } else {
                $path = Branding::store($kind, $_FILES[$kind] ?? null);
                Audit::log('settings.branding.upload', 'branding', $kind, ['file' => basename($path)]);
                Http::flash('success', ucfirst($kind) . ' updated.');
            }
        } catch (ValidationException $e) {
            Audit::log('settings.branding.upload', 'branding', $kind, ['error' => $e->getMessage()], 'failure');
            $_SESSION['_brand_error'] = $e->getMessage();
        }
        Http::redirect(url('settings.appearance') . '#branding');
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
            Audit::log('settings.security_mode', 'settings', 'security_mode', ['to' => 'production']);
            Http::flash('success', 'HTTPS is now required. Visitors using http:// are redirected to https://.');
        } elseif ($mode === 'testing') {
            Settings::set(['security_mode' => 'testing']);
            Audit::log('settings.security_mode', 'settings', 'security_mode', ['to' => 'testing']);
            Http::flash('success', 'Testing mode enabled: plain HTTP is allowed again (with a warning).');
        }
        Http::redirect(url('settings.system') . '#https');
    }

    public function migrate(): void
    {
        $ran = (new Migrator(DB::pdo()))->migrate();
        Audit::log('system.migrate', 'schema', (string) max($ran ?: [0]), ['versions' => $ran]);
        Http::flash('success', $ran ? 'Applied database updates: ' . implode(', ', $ran) . '.' : 'The database is already up to date.');
        Http::redirect(url('settings.system'));
    }
}
