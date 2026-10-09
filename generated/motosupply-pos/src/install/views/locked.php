<?php defined('MOTO_ROOT') || exit; ?>
<h1>MotoSupply is already installed</h1>
<p class="lead">The installer is locked. It will not change your configuration, database or administrator account.</p>
<div class="wizard-actions">
  <a class="btn btn-primary" href="<?= e(\App\Core\Http::basePath() . '/index.php?r=login') ?>">Go to Login</a>
</div>
<p class="muted small">Need to reinstall from scratch? See “Reinstalling” in README.md. It requires access to your hosting File Manager, so visitors cannot do it.</p>
