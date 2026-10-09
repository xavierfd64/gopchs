<?php defined('MOTO_ROOT') || exit; require_once __DIR__ . '/partials.php'; ?>
<h1>Shop information</h1>
<p class="lead">This appears on receipts and reports. You can change it later in Settings.</p>
<form method="post" action="<?= e(wizard_url('shop')) ?>" class="stack" novalidate data-once>
  <?= csrf_field() ?>
  <div class="form-grid">
    <div class="field span-2">
      <label for="shop_name">Shop name</label>
      <input id="shop_name" name="shop_name" required maxlength="100" value="<?= e($v['shop_name']) ?>"<?= f_inv($errors, 'shop_name') ?>>
      <?= f_err($errors, 'shop_name') ?>
    </div>
    <div class="field span-2">
      <label for="shop_address">Shop address <span class="muted">(optional)</span></label>
      <input id="shop_address" name="shop_address" maxlength="255" value="<?= e($v['shop_address']) ?>"<?= f_inv($errors, 'shop_address') ?>>
      <?= f_err($errors, 'shop_address') ?>
    </div>
    <div class="field">
      <label for="shop_phone">Contact number <span class="muted">(optional)</span></label>
      <input id="shop_phone" name="shop_phone" type="tel" maxlength="50" value="<?= e($v['shop_phone']) ?>" placeholder="+63 917 555 0182"<?= f_inv($errors, 'shop_phone') ?>>
      <?= f_err($errors, 'shop_phone') ?>
    </div>
    <div class="field">
      <label for="currency_code">Currency</label>
      <select id="currency_code" name="currency_code"<?= f_inv($errors, 'currency_code') ?>>
        <?php foreach (Installer::CURRENCIES as $code => [$label]): ?>
          <option value="<?= e($code) ?>"<?= $v['currency_code'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?= f_err($errors, 'currency_code') ?>
    </div>
    <div class="field span-2">
      <label for="timezone">Timezone</label>
      <select id="timezone" name="timezone"<?= f_inv($errors, 'timezone') ?>>
        <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
          <option value="<?= e($tz) ?>"<?= $v['timezone'] === $tz ? ' selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
      <?= f_err($errors, 'timezone') ?>
    </div>
  </div>
  <div class="wizard-actions">
    <a class="btn" href="<?= e(wizard_url('database')) ?>">Back</a>
    <button type="submit" class="btn btn-primary">Continue</button>
  </div>
</form>
