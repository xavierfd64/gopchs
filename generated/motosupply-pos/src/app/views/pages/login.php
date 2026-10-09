<section class="auth-card" aria-labelledby="login-title">
  <h1 id="login-title">Sign in</h1>
  <p class="muted">Log in to the point-of-sale and inventory system.</p>
  <?php if ($expired): ?><div class="alert alert-info" role="status"><?= icon('clock') ?><span>Your session expired. Please log in again.</span></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
  <form method="post" action="<?= e(url('login')) ?>" class="stack" data-once>
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" autocomplete="username" required maxlength="50" value="<?= e($username) ?>" autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="200">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Log in</button>
  </form>
</section>
