<?php defined('MOTO_ROOT') || exit; ?>
<div class="done-icon" aria-hidden="true">✓</div>
<h1 class="center-text">Installation completed successfully</h1>
<p class="lead center-text">MotoSupply POS is ready. Log in with the administrator account <strong><?= e($username) ?></strong>.</p>
<dl class="summary-list">
  <div><dt>Login page</dt><dd><a href="<?= e($loginUrl) ?>"><?= e((\App\Core\Http::isHttps() ? 'https://' : 'http://') . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) . $loginUrl) ?></a></dd></div>
  <div><dt>Installer</dt><dd>Locked. It cannot be run again, and it can never reset your password or overwrite your data.</dd></div>
  <div><dt>Configuration</dt><dd><?= $configPrivate ? 'Saved privately outside the public web folder.' : 'Saved in the protected config/ folder (web access is blocked).' ?></dd></div>
</dl>
<div class="alert alert-info"><span><strong>Keep your administrator password secure.</strong> Do not share it, and use HTTPS for daily use. For extra safety you may delete the <code>install</code> folder using your File Manager.</span></div>
<div class="wizard-actions">
  <a class="btn btn-primary btn-lg" href="<?= e($loginUrl) ?>">Go to Login</a>
</div>
