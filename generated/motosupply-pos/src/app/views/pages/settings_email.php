<?php
use App\Services\EmailReports;
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$val = static fn (string $k): string => e($s[$k] ?? '');
$sections = array_filter(explode(',', (string) ($s['email_sections'] ?? '')));
$toggle = static function (string $key, string $label, string $hint) use ($s): string {
    $on = ($s[$key] ?? '0') === '1';
    return '<div class="toggle-row"><div><label for="' . $key . '"><strong>' . e($label) . '</strong></label><small class="muted block">' . e($hint) . '</small></div>'
        . '<input type="hidden" name="' . $key . '" value="0"><input class="switch" type="checkbox" role="switch" id="' . $key . '" name="' . $key . '" value="1"' . ($on ? ' checked' : '') . '></div>';
};
$cronUrl = (\App\Core\Http::isHttps() ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'your-site')) . \App\Core\Http::basePath() . '/index.php?r=cron.daily-report&key=';
?>
<div class="page">
  <div class="page-head"><div><h2 class="page-title">Settings</h2><p class="muted">Automatic end-of-day email reports.</p></div></div>
  <?php include __DIR__ . '/../partials/settings_tabs.php'; ?>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span>Settings were not saved. Please correct the highlighted fields.</span></div><?php endif; ?>
  <form class="card card-pad stack" method="post" action="<?= e(url('settings.email')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <h3>Daily report</h3>
    <?= $toggle('email_enabled', 'Send the daily report', 'Each report covers one complete day (00:00–23:59) and is sent on the following day at or after the delivery time.') ?>
    <div class="form-grid">
      <div class="field span-2"><label for="email_recipients">Recipients</label><input id="email_recipients" name="email_recipients" value="<?= $val('email_recipients') ?>" placeholder="owner@example.com, manager@example.com"<?= $inv('email_recipients') ?>><small class="hint">Up to 10 addresses, separated by commas.</small><?= $err('email_recipients') ?></div>
      <div class="field"><label for="email_time">Delivery time</label><input id="email_time" name="email_time" type="time" value="<?= $val('email_time') ?>"<?= $inv('email_time') ?>><small class="hint">Sent at or after this time for the previous day.</small><?= $err('email_time') ?></div>
      <div class="field"><label for="email_timezone">Report timezone</label>
        <select id="email_timezone" name="email_timezone"<?= $inv('email_timezone') ?>>
          <option value="">Same as shop (<?= e(\App\Core\Settings::get('timezone')) ?>)</option>
          <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= ($s['email_timezone'] ?? '') === $tz ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
        </select><?= $err('email_timezone') ?></div>
    </div>
    <fieldset class="field"><legend>Included sections</legend>
      <div class="check-grid">
        <?php foreach (EmailReports::SECTIONS as $k => $label): ?>
          <label class="check"><input type="checkbox" name="email_sections[]" value="<?= $k ?>"<?= in_array($k, $sections, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?= $toggle('email_attach_pdf', 'Attach PDF reports', 'Sales summary and transaction list as PDF files.') ?>
    <?= $toggle('email_attach_csv', 'Attach CSV transaction list', 'Opens in Excel or Google Sheets.') ?>

    <h3 id="smtp">Sending method</h3>
    <div class="form-grid">
      <div class="field"><label for="mail_transport">Send using</label>
        <select id="mail_transport" name="mail_transport">
          <option value="smtp"<?= ($s['mail_transport'] ?? 'smtp') === 'smtp' ? ' selected' : '' ?>>SMTP server (recommended)</option>
          <option value="phpmail"<?= ($s['mail_transport'] ?? '') === 'phpmail' ? ' selected' : '' ?>>PHP mail() (often disabled on free hosting)</option>
        </select></div>
      <div class="field"><label for="mail_from_address">From address</label><input id="mail_from_address" name="mail_from_address" type="email" value="<?= $val('mail_from_address') ?>" placeholder="reports@your-domain.com"<?= $inv('mail_from_address') ?>><?= $err('mail_from_address') ?></div>
      <div class="field"><label for="mail_from_name">From name</label><input id="mail_from_name" name="mail_from_name" value="<?= $val('mail_from_name') ?>" placeholder="<?= e(\App\Core\Settings::get('shop_name')) ?>"<?= $inv('mail_from_name') ?>><?= $err('mail_from_name') ?></div>
      <div class="field"><label for="smtp_host">SMTP host</label><input id="smtp_host" name="smtp_host" value="<?= $val('smtp_host') ?>" placeholder="smtp.gmail.com" autocomplete="off"<?= $inv('smtp_host') ?>><?= $err('smtp_host') ?></div>
      <div class="field"><label for="smtp_port">Port</label><input id="smtp_port" name="smtp_port" inputmode="numeric" value="<?= $val('smtp_port') ?>"<?= $inv('smtp_port') ?>><?= $err('smtp_port') ?></div>
      <div class="field"><label for="smtp_encryption">Encryption</label>
        <select id="smtp_encryption" name="smtp_encryption">
          <?php foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (not recommended)'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= ($s['smtp_encryption'] ?? 'tls') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="smtp_username">SMTP username</label><input id="smtp_username" name="smtp_username" value="<?= $val('smtp_username') ?>" autocomplete="off"<?= $inv('smtp_username') ?>><?= $err('smtp_username') ?></div>
      <div class="field"><label for="smtp_password">SMTP password</label><input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password" placeholder="<?= ($s['smtp_password_enc'] ?? '') !== '' ? 'Saved (leave blank to keep)' : 'Not set' ?>"<?= $inv('smtp_password') ?>><?= $err('smtp_password') ?>
        <?php if (($s['smtp_password_enc'] ?? '') !== ''): ?><label class="check small"><input type="checkbox" name="smtp_password_clear" value="1"> Remove saved password</label><?php endif; ?>
        <small class="hint">Stored encrypted and never shown again. For Gmail, use an app password.</small></div>
    </div>

    <h3 id="schedule">Schedule trigger</h3>
    <p class="small muted">Shared hosting has no always-running background process, so something must start the daily check. Choose one or more; the report is still sent only once per day.</p>
    <ol class="small schedule-list">
      <li><strong>Hosting cron job (best, cPanel):</strong> Cron Jobs → every 15 minutes → command:<br><code class="break">php <?= e((string) realpath(MOTO_ROOT . '/app/cli/daily-report.php')) ?></code> (this page is visible only to signed-in administrators).</li>
      <li><strong>External scheduler (InfinityFree and hosts without cron):</strong> use a free service such as cron-job.org to open this HTTPS address every 15 minutes:<br>
        <?php if ($cronKey): ?>
          <code class="break"><?= e($cronUrl . $cronKey) ?></code><br><strong>Copy it now.</strong> The secret is shown only once (only its fingerprint is stored).
        <?php else: ?>
          <code class="break"><?= e($cronUrl) ?>…secret…</code>
        <?php endif; ?>
        <?php if (!$https): ?><br><span class="text-warning">Requires HTTPS: the address does not work over http://.</span><?php endif; ?>
      </li>
      <li><strong>On staff visits (fallback):</strong> <?= $toggle('email_on_visit', 'Check when someone uses MotoSupply', 'The report goes out on the first page load after the delivery time. If nobody uses the system, it waits, so timing is not exact.') ?></li>
    </ol>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save email settings</button></div>
  </form>

  <section class="card card-pad stack">
    <h3>Test and send</h3>
    <div class="btn-row">
      <form method="post" action="<?= e(url('settings.email.test')) ?>" data-once><?= csrf_field() ?><button class="btn" type="submit"><?= icon('mail') ?> Send test email</button></form>
      <form method="post" action="<?= e(url('settings.email.cron-key')) ?>" data-confirm="Create a new secret? The old scheduler address will stop working."><?= csrf_field() ?><button class="btn" type="submit"><?= icon('lock') ?> New scheduler secret</button></form>
      <form method="post" action="<?= e(url('settings.email.send-now')) ?>" class="inline-form" data-once><?= csrf_field() ?>
        <label class="visually-hidden" for="send-date">Report date</label>
        <input id="send-date" type="date" name="date" class="input-sm" value="<?= e((new DateTimeImmutable('yesterday', new DateTimeZone(EmailReports::timezone())))->format('Y-m-d')) ?>">
        <button class="btn btn-sm" type="submit">Send report for this day</button></form>
    </div>
    <?php if ($runs): ?>
      <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th scope="col">Report date</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Trigger</th><th scope="col">Last update</th><th scope="col">Error</th></tr></thead>
        <tbody><?php foreach ($runs as $r): ?>
          <tr><td data-label="Report date"><?= e($r['report_date']) ?></td>
            <td data-label="Status"><span class="pill <?= $r['status'] === 'sent' ? 'pill-success' : ($r['status'] === 'failed' ? 'pill-danger' : 'pill-muted') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td data-label="Attempts"><?= (int) $r['attempts'] ?></td><td data-label="Trigger"><?= e($r['trigger_src']) ?></td>
            <td data-label="Last update"><?= e(local_time($r['updated_at'])) ?></td><td data-label="Error"><?= e($r['error']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php else: ?><p class="muted small">No reports have been sent yet.</p><?php endif; ?>
  </section>
</div>
