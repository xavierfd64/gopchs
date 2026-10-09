<?php
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$val = static fn (string $k): string => e($s[$k] ?? '');
$toggle = static function (string $key, string $label, string $hint) use ($s): string {
    $on = ($s[$key] ?? '0') === '1';
    return '<div class="toggle-row"><div><label for="' . $key . '"><strong>' . e($label) . '</strong></label><small class="muted block">' . e($hint) . '</small></div>'
        . '<input type="hidden" name="' . $key . '" value="0"><input class="switch" type="checkbox" role="switch" id="' . $key . '" name="' . $key . '" value="1"' . ($on ? ' checked' : '') . '></div>';
};
?>
<div class="page">
  <div class="page-head">
    <div>
      <h2 class="page-title">Settings</h2>
      <p class="muted">Configure store, receipt, POS, and inventory preferences.</p>
    </div>
    <button type="submit" form="settings-form" class="btn btn-primary">Save Changes</button>
  </div>
  <div class="settings-layout">
    <nav class="settings-nav" aria-label="Settings sections">
      <a href="#store"><?= icon('store') ?> Store Information</a>
      <a href="#receipt"><?= icon('receipt') ?> Receipt Information</a>
      <a href="#currency"><?= icon('cash') ?> Currency &amp; Time</a>
      <a href="#pos-prefs"><?= icon('pos') ?> POS Preferences</a>
      <a href="#inventory-prefs"><?= icon('inventory') ?> Inventory Preferences</a>
      <a href="#security"><?= icon('shield') ?> Password &amp; Security</a>
      <a href="<?= e(url('settings.system')) ?>"><?= icon('settings') ?> System Check</a>
    </nav>
    <div class="stack">
      <form id="settings-form" class="card card-pad stack" method="post" action="<?= e(url('settings.save')) ?>" data-once novalidate>
        <?= csrf_field() ?>
        <section id="store" class="settings-section">
          <h3>Store Information</h3>
          <p class="muted small">Details shown on receipts and printed reports.</p>
          <div class="form-grid">
            <div class="field"><label for="shop_name">Store name</label><input id="shop_name" name="shop_name" maxlength="100" required value="<?= $val('shop_name') ?>"<?= $inv('shop_name') ?>><?= $err('shop_name') ?></div>
            <div class="field"><label for="shop_phone">Business phone</label><input id="shop_phone" name="shop_phone" maxlength="50" value="<?= $val('shop_phone') ?>" placeholder="+63 917 555 0182"<?= $inv('shop_phone') ?>><?= $err('shop_phone') ?></div>
            <div class="field span-2"><label for="shop_address">Store address</label><input id="shop_address" name="shop_address" maxlength="255" value="<?= $val('shop_address') ?>"<?= $inv('shop_address') ?>><?= $err('shop_address') ?></div>
            <div class="field"><label for="shop_email">Email</label><input id="shop_email" name="shop_email" type="email" maxlength="100" value="<?= $val('shop_email') ?>"<?= $inv('shop_email') ?>><?= $err('shop_email') ?></div>
          </div>
        </section>
        <section id="receipt" class="settings-section">
          <h3>Receipt Information</h3>
          <div class="field"><label for="receipt_footer">Receipt footer message</label><input id="receipt_footer" name="receipt_footer" maxlength="200" value="<?= $val('receipt_footer') ?>"<?= $inv('receipt_footer') ?>><?= $err('receipt_footer') ?></div>
        </section>
        <section id="currency" class="settings-section">
          <h3>Currency &amp; Time</h3>
          <div class="form-grid">
            <div class="field"><label for="currency_code">Currency</label>
              <select id="currency_code" name="currency_code"<?= $inv('currency_code') ?>>
                <?php foreach ($currencies as $code => [$label]): ?><option value="<?= e($code) ?>"<?= ($s['currency_code'] ?? '') === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select><?= $err('currency_code') ?></div>
            <div class="field"><label for="timezone">Timezone</label>
              <select id="timezone" name="timezone"<?= $inv('timezone') ?>>
                <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= ($s['timezone'] ?? '') === $tz ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
              </select><?= $err('timezone') ?></div>
          </div>
        </section>
        <section id="pos-prefs" class="settings-section">
          <h3>POS Preferences</h3>
          <?= $toggle('pos_auto_add_barcode', 'Automatically add exact barcode matches', 'Scanned products are added to the current sale immediately.') ?>
          <?= $toggle('pos_confirm_clear', 'Confirm before clearing a sale', 'Prevents accidental removal of all cart products.') ?>
        </section>
        <section id="inventory-prefs" class="settings-section">
          <h3>Inventory Preferences</h3>
          <?= $toggle('show_low_stock_badge', 'Show low-stock notifications', 'Shows the low-stock count in the sidebar and header.') ?>
          <div class="field narrow"><label for="low_stock_threshold">Default low-stock threshold</label><input id="low_stock_threshold" name="low_stock_threshold" inputmode="numeric" maxlength="6" value="<?= $val('low_stock_threshold') ?>"<?= $inv('low_stock_threshold') ?>><small class="hint">Used for products without their own threshold.</small><?= $err('low_stock_threshold') ?></div>
        </section>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save Changes</button></div>
      </form>

      <form id="security" class="card card-pad stack" method="post" action="<?= e(url('settings.password')) ?>" data-once>
        <?= csrf_field() ?>
        <h3>Password &amp; Security</h3>
        <p class="muted small">Change the administrator password. You will stay logged in on this device.</p>
        <?php if ($pwError): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($pwError) ?></span></div><?php endif; ?>
        <div class="form-grid">
          <div class="field span-2"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
          <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
          <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Change password</button></div>
      </form>

      <form class="card card-pad" method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() ?>
        <div class="toggle-row"><div><strong>Log out</strong><small class="muted block">End your session on this device.</small></div><button type="submit" class="btn"><?= icon('logout') ?> Log out</button></div>
      </form>
    </div>
  </div>
</div>
