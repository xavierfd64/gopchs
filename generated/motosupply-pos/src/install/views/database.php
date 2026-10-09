<?php defined('MOTO_ROOT') || exit; require_once __DIR__ . '/partials.php'; ?>
<h1>Database connection</h1>
<p class="lead">Enter the details of the MySQL database you created. <strong>Copy them from your hosting control panel.</strong> They differ between hosts, and the host is often <em>not</em> “localhost”.</p>
<details class="help-box">
  <summary>Where do I find these?</summary>
  <ul>
    <li><strong>InfinityFree:</strong> Client Area → your account → <em>MySQL Databases</em>. The host looks like <code>sql123.infinityfree.com</code>, the name and username start with <code>if0_</code>, and the password is your hosting account password (shown in the Client Area).</li>
    <li><strong>cPanel:</strong> <em>MySQL® Databases</em>. The host is usually <code>localhost</code>; the database and user names start with your cPanel username, e.g. <code>myname_motosupply</code>. Make sure the user is added to the database with <em>All Privileges</em>.</li>
  </ul>
</details>
<form method="post" action="<?= e(wizard_url('database')) ?>" class="stack" novalidate data-once>
  <?= csrf_field() ?>
  <div class="form-grid">
    <div class="field">
      <label for="db_host">Database host</label>
      <input id="db_host" name="db_host" required autocomplete="off" spellcheck="false" value="<?= e($v['host']) ?>" placeholder="e.g. sql123.infinityfree.com"<?= f_inv($errors, 'db_host') ?>>
      <?= f_err($errors, 'db_host') ?>
    </div>
    <div class="field">
      <label for="db_port">Port <span class="muted">(optional)</span></label>
      <input id="db_port" name="db_port" inputmode="numeric" value="<?= e((string) $v['port']) ?>" placeholder="3306"<?= f_inv($errors, 'db_port') ?>>
      <?= f_err($errors, 'db_port') ?>
    </div>
    <div class="field">
      <label for="db_name">Database name</label>
      <input id="db_name" name="db_name" required autocomplete="off" spellcheck="false" value="<?= e($v['name']) ?>" placeholder="e.g. if0_12345678_motosupply"<?= f_inv($errors, 'db_name') ?>>
      <?= f_err($errors, 'db_name') ?>
    </div>
    <div class="field">
      <label for="db_user">Database username</label>
      <input id="db_user" name="db_user" required autocomplete="off" spellcheck="false" value="<?= e($v['user']) ?>" placeholder="e.g. if0_12345678"<?= f_inv($errors, 'db_user') ?>>
      <?= f_err($errors, 'db_user') ?>
    </div>
    <div class="field span-2">
      <label for="db_pass">Database password</label>
      <input id="db_pass" name="db_pass" type="password" autocomplete="new-password"<?= f_inv($errors, 'db_pass', 'pass-hint') ?>>
      <small class="hint" id="pass-hint"><?= $hasPassword ? 'A password was already entered. Leave this blank to keep it, or type a new one.' : 'The password is never shown again and is not written to any log.' ?></small>
      <?= f_err($errors, 'db_pass') ?>
    </div>
  </div>
  <div class="wizard-actions">
    <a class="btn" href="<?= e(wizard_url('requirements')) ?>">Back</a>
    <button type="submit" name="action" value="test" class="btn">Test connection</button>
    <button type="submit" name="action" value="continue" class="btn btn-primary">Continue</button>
  </div>
</form>
