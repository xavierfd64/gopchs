<?php
$toggle = static function (string $key, string $label, string $hint) use ($s): string {
    $on = ($s[$key] ?? '0') === '1';
    return '<div class="toggle-row"><div><label for="' . $key . '"><strong>' . e($label) . '</strong></label><small class="muted block">' . e($hint) . '</small></div>'
        . '<input type="hidden" name="' . $key . '" value="0"><input class="switch" type="checkbox" role="switch" id="' . $key . '" name="' . $key . '" value="1"' . ($on ? ' checked' : '') . '></div>';
};
?>
<div class="page">
  <div class="page-head"><div><h2 class="page-title">Settings</h2><p class="muted">Receipt and printing preferences.</p></div></div>
  <?php include __DIR__ . '/../partials/settings_tabs.php'; ?>
  <form class="card card-pad stack" method="post" action="<?= e(url('settings.receipt')) ?>" data-once>
    <?= csrf_field() ?>
    <h3>Receipt printing</h3>
    <?= $toggle('receipt_auto_print', 'Print receipt automatically after each sale', 'Opens the browser print dialog as soon as a sale is saved. The sale is always saved first, even if printing fails or no printer is connected.') ?>
    <?= $toggle('receipt_show_logo', 'Show the business logo on receipts', 'Uses the logo from Settings → Appearance (if one is uploaded).') ?>
    <div class="field narrow">
      <label for="receipt_paper">Receipt paper</label>
      <select id="receipt_paper" name="receipt_paper">
        <?php foreach (['80mm' => '80 mm thermal roll', '58mm' => '58 mm thermal roll', 'a4' => 'A4 / Letter page'] as $k => $label): ?>
          <option value="<?= $k ?>"<?= ($s['receipt_paper'] ?? '80mm') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <a class="btn" href="<?= e(url('receipt.test', ['print' => 1])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Test print</a>
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </form>
  <section class="card card-pad stack small">
    <h3>About browser printing</h3>
    <p>MotoSupply prints through your web browser, so it works with any printer your computer or tablet can print to. It cannot detect whether a printer is physically connected, and it cannot print silently: the browser shows its print dialog. To print without the dialog, you can run Chrome in kiosk mode with the <code>--kiosk-printing</code> option on the POS computer. That is a browser setting, not part of MotoSupply.</p>
    <p>If printing fails or is cancelled, the sale is still saved, and you can reprint it any time from Sales History.</p>
  </section>
</div>
