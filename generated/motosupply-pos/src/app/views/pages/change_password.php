<section class="auth-card" aria-labelledby="pw-title">
  <h1 id="pw-title"><?= $forced ? 'Set a new password' : 'Change password' ?></h1>
  <?php if ($forced): ?>
    <p class="muted">You are using a temporary password. Choose a new password before using the system.</p>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
  <form method="post" action="<?= e(url('password.change')) ?>" class="stack" data-once>
    <?= csrf_field() ?>
    <div class="field">
      <label for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
    </div>
    <div class="field">
      <label for="new_password">New password</label>
      <input id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="8" maxlength="72" aria-describedby="pw-hint">
      <small id="pw-hint" class="hint">At least 8 characters. Avoid “admin”, your username and other easy guesses.</small>
    </div>
    <div class="field">
      <label for="confirm_password">Confirm new password</label>
      <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="8" maxlength="72">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Save new password</button>
  </form>
  <form method="post" action="<?= e(url('logout')) ?>" class="center-text">
    <?= csrf_field() ?>
    <button type="submit" class="btn-link">Log out</button>
  </form>
</section>
