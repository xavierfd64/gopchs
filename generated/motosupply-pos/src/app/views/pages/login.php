<?php
use App\Core\Settings;
use App\Services\Branding;
$logo = Branding::logoUrl();
$shop = Settings::get('shop_name');
?>
<section class="login-shell" aria-labelledby="login-title">
  <div class="login-brand">
    <div class="login-brand-inner">
      <?php if ($logo): ?>
        <img class="login-logo" src="<?= e($logo) ?>" alt="<?= e($shop) ?>">
      <?php else: ?>
        <span class="brand-mark brand-mark-lg" aria-hidden="true">M</span>
      <?php endif; ?>
      <p class="login-shop"><?= e($shop) ?></p>
      <p class="login-tag">Point of sale &amp; inventory</p>
    </div>
  </div>
  <div class="login-form-wrap">
    <h1 id="login-title">Sign in</h1>
    <p class="muted">Use the account your administrator gave you.</p>
    <?php if ($expired): ?><div class="alert alert-info" role="status"><?= icon('clock') ?><span>Your session expired. Please sign in again.</span></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert" id="login-error"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="post" action="<?= e(url('login')) ?>" class="stack" data-once novalidate data-validate-login>
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="50" value="<?= e($username) ?>" <?= $username === '' ? 'autofocus' : '' ?><?= $error ? ' aria-describedby="login-error"' : '' ?>>
        <p class="field-error" data-error-for="username" hidden>Enter your username.</p>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="200" <?= $username !== '' ? 'autofocus' : '' ?>>
          <button type="button" class="pw-toggle" data-pw-toggle="password" aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
        </div>
        <p class="field-error" data-error-for="password" hidden>Enter your password.</p>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in</button>
    </form>
  </div>
</section>
