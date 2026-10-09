<?php defined('MOTO_ROOT') || exit; require_once __DIR__ . '/partials.php'; ?>
<h1>Administrator account</h1>
<p class="lead">Create the account you will use to log in. Choose a strong password that you do not use anywhere else.</p>
<form method="post" action="<?= e(wizard_url('admin')) ?>" class="stack" novalidate data-once>
  <?= csrf_field() ?>
  <div class="form-grid">
    <div class="field">
      <label for="admin_user">Username</label>
      <input id="admin_user" name="admin_user" required maxlength="50" autocomplete="username" autocapitalize="off" spellcheck="false" value="<?= e($v['admin_user']) ?>"<?= f_inv($errors, 'admin_user') ?>>
      <?= f_err($errors, 'admin_user') ?>
    </div>
    <div class="field">
      <label for="admin_name">Your name <span class="muted">(optional)</span></label>
      <input id="admin_name" name="admin_name" maxlength="100" autocomplete="name" value="<?= e($v['admin_name']) ?>"<?= f_inv($errors, 'admin_name') ?>>
      <?= f_err($errors, 'admin_name') ?>
    </div>
    <div class="field">
      <label for="admin_pass">Password</label>
      <input id="admin_pass" name="admin_pass" type="password" required maxlength="72" autocomplete="new-password"<?= f_inv($errors, 'admin_pass', 'pw-rules') ?>>
      <?= f_err($errors, 'admin_pass') ?>
    </div>
    <div class="field">
      <label for="admin_pass2">Confirm password</label>
      <input id="admin_pass2" name="admin_pass2" type="password" required maxlength="72" autocomplete="new-password"<?= f_inv($errors, 'admin_pass2') ?>>
      <?= f_err($errors, 'admin_pass2') ?>
    </div>
  </div>
  <p class="hint" id="pw-rules">At least 8 characters, using at least three of: lowercase letters, uppercase letters, numbers, symbols. It must not contain the username or a common word such as “password” or “admin”.</p>
  <div class="wizard-actions">
    <a class="btn" href="<?= e(wizard_url('shop')) ?>">Back</a>
    <button type="submit" class="btn btn-primary">Continue</button>
  </div>
</form>
