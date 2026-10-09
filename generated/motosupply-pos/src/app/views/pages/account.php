<?php use App\Core\Auth; $u = Auth::user(); ?>
<div class="page page-narrow">
  <div class="page-head"><div><h2 class="page-title">My account</h2><p class="muted"><?= e($u['username']) ?> · <?= e($u['role_name'] ?? '') ?></p></div></div>
  <form id="password" class="card card-pad stack" method="post" action="<?= e(url('account.password')) ?>" data-once>
    <?= csrf_field() ?>
    <h3>Change password</h3>
    <?php if ($pwError): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($pwError) ?></span></div><?php endif; ?>
    <div class="form-grid">
      <div class="field span-2"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
      <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
      <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
    </div>
    <p class="hint">At least 8 characters, using three of: lowercase, uppercase, numbers, symbols.</p>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Change password</button></div>
  </form>
  <?php if (Auth::can('sales.void.approve')): ?>
  <form id="void-pin" class="card card-pad stack" method="post" action="<?= e(url('account.pin')) ?>" data-once>
    <?= csrf_field() ?>
    <h3>Void approval PIN</h3>
    <p class="small muted">You can approve voids. When a cashier voids a sale, you approve it by entering your username and this PIN. It is separate from your login password, stored only as a secure hash, and never shown again. Five wrong entries lock approvals for 15 minutes.</p>
    <p>Status: <?= (int) $u['has_void_pin'] === 1 ? '<span class="pill pill-success">PIN set</span>' : '<span class="pill pill-warning">No PIN yet: you cannot approve voids</span>' ?></p>
    <?php if ($pinError): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($pinError) ?></span></div><?php endif; ?>
    <div class="form-grid">
      <div class="field span-2"><label for="pin_current_password">Your login password (to confirm it is you)</label><input id="pin_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
      <div class="field"><label for="pin">New void PIN (6–12 digits)</label><input id="pin" name="pin" type="password" inputmode="numeric" pattern="\d{6,12}" maxlength="12" autocomplete="off" required></div>
      <div class="field"><label for="pin2">Confirm PIN</label><input id="pin2" name="pin2" type="password" inputmode="numeric" pattern="\d{6,12}" maxlength="12" autocomplete="off" required></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= (int) $u['has_void_pin'] === 1 ? 'Change PIN' : 'Set PIN' ?></button></div>
  </form>
  <?php endif; ?>
  <form class="card card-pad" method="post" action="<?= e(url('logout')) ?>">
    <?= csrf_field() ?>
    <div class="toggle-row"><div><strong>Sign out</strong><small class="muted block">End your session on this device.</small></div><button type="submit" class="btn"><?= icon('logout') ?> Log out</button></div>
  </form>
</div>
