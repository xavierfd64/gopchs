<?php
use App\Core\Settings;
$isVoid = $sale['status'] === 'voided';
?>
<?php if (empty($embed)): ?>
<div class="receipt-actions no-print">
  <button type="button" data-print>Print receipt</button>
  <?php if (empty($test) && \App\Core\Auth::can('sales.view')): ?><a href="<?= e(url('sales.view', ['id' => $sale['id']])) ?>">View sale</a><?php endif; ?>
  <?php if (\App\Core\Auth::can('pos.access')): ?><a href="<?= e(url('pos')) ?>">Back to POS</a><?php endif; ?>
</div>
<?php endif; ?>
<?php if (!empty($test)): ?><p class="test-banner no-print">Test print: no sale was recorded. Paper: <?= e($paper) ?></p><?php endif; ?>
<article class="receipt">
  <header>
    <?php $logo = Settings::get('receipt_show_logo') === '1' ? \App\Services\Branding::logoUrl() : null; ?>
    <?php if ($logo): ?><img class="receipt-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
    <h1><?= e(Settings::get('shop_name')) ?></h1>
    <?php if (Settings::get('shop_address') !== ''): ?><p><?= e(Settings::get('shop_address')) ?></p><?php endif; ?>
    <?php $contact = array_filter([Settings::get('shop_phone'), Settings::get('shop_email')]); ?>
    <?php if ($contact): ?><p><?= e(implode(' · ', $contact)) ?></p><?php endif; ?>
  </header>
  <?php if ($isVoid): ?><p class="void-banner">*** VOIDED ***</p><?php endif; ?>
  <?php if (!empty($test)): ?><p class="void-banner">*** TEST PRINT ***</p><?php endif; ?>
  <dl class="meta">
    <div><dt>Transaction</dt><dd><?= e($sale['transaction_no']) ?></dd></div>
    <div><dt>Date</dt><dd><?= e(local_time($sale['created_at'], 'M j, Y g:i A')) ?></dd></div>
    <div><dt>Cashier</dt><dd><?= e($sale['cashier']) ?></dd></div>
  </dl>
  <table>
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($sale['items'] as $it): ?>
      <tr>
        <td><?= e($it['product_name']) ?><small><?= e($it['sku']) ?></small></td>
        <td class="num"><?= (int) $it['quantity'] ?></td>
        <td class="num"><?= money($it['unit_price']) ?></td>
        <td class="num"><?= money($it['line_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <dl class="totals">
    <div><dt>Subtotal</dt><dd><?= money($sale['subtotal']) ?></dd></div>
    <?php if (\App\Core\Money::toCents($sale['discount_amount']) > 0): ?>
      <div><dt>Discount</dt><dd>-<?= money($sale['discount_amount']) ?></dd></div>
    <?php endif; ?>
    <div class="grand"><dt>TOTAL</dt><dd><?= money($sale['total']) ?></dd></div>
    <div><dt>Cash tendered</dt><dd><?= money($sale['amount_tendered']) ?></dd></div>
    <div><dt>Change</dt><dd><?= money($sale['change_due']) ?></dd></div>
  </dl>
  <p class="items-count"><?= e(plural((int) $sale['item_count'], 'item')) ?></p>
  <?php if (Settings::get('receipt_footer') !== ''): ?><footer><?= e(Settings::get('receipt_footer')) ?></footer><?php endif; ?>
</article>
