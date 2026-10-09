<?php use App\Core\Auth; $s = $scan; ?>
<div class="page">
  <div class="page-head"><div>
    <a class="back-link" href="<?= e(url('products')) ?>">← Inventory</a>
    <h2 class="page-title">Inventory integrity</h2>
    <p class="muted">Checks stock against the movement history and sales. Nothing is changed automatically; every correction is recorded in the stock history and the audit log.</p>
  </div></div>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

  <div class="stat-strip">
    <div><span class="stat-label">Issues found</span><strong class="stat-value <?= $s['issues'] ? 'text-danger' : 'text-success' ?>"><?= (int) $s['issues'] ?></strong><small class="muted"><?= $s['issues'] ? 'Review below' : 'Stock and history agree' ?></small></div>
    <div><span class="stat-label">Negative-stock guard</span><strong class="stat-value <?= $s['guard'] ? 'text-success' : 'text-warning' ?>"><?= $s['guard'] ? 'On' : 'Off' ?></strong><small class="muted"><?= $s['guard'] ? 'The database refuses negative stock' : 'Fix negative stock, then turn it on' ?></small></div>
    <div><span class="stat-label">Transactional tables</span><strong class="stat-value <?= $s['non_transactional'] ? 'text-danger' : 'text-success' ?>"><?= $s['non_transactional'] ? 'No' : 'Yes' ?></strong><small class="muted"><?= $s['non_transactional'] ? 'Sales are paused: ' . e(implode(', ', $s['non_transactional'])) : 'InnoDB (rollback protected)' ?></small></div>
  </div>
  <?php if (!$s['guard'] && Auth::can('inventory.adjust')): ?>
    <form method="post" action="<?= e(url('inventory.integrity.guard')) ?>" class="card card-pad mode-box"><?= csrf_field() ?>
      <span>Turn on the database guard so negative stock can never be stored, whatever happens in the application.</span>
      <button class="btn btn-primary" type="submit"<?= $s['negative'] ? ' disabled' : '' ?>>Turn on guard</button></form>
  <?php endif; ?>

  <?php
  $section = static function (string $title, string $help, array $rows, array $cols, ?callable $action = null): void { ?>
    <section class="card">
      <div class="card-head"><div><h3><?= e($title) ?> <span class="pill <?= $rows ? 'pill-danger' : 'pill-success' ?>"><?= count($rows) ?></span></h3><p class="muted small"><?= e($help) ?></p></div></div>
      <?php if ($rows): ?>
      <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?php foreach ($cols as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?><?php if ($action): ?><th scope="col">Correct</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr>
          <?php foreach ($cols as $k => $label): ?><td data-label="<?= e($label) ?>"><?= e($k === 'created_at' ? local_time((string) $r[$k]) : (string) $r[$k]) ?></td><?php endforeach; ?>
          <?php if ($action): ?><td data-label="Correct"><?php $action($r); ?></td><?php endif; ?>
        </tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </section>
  <?php };
  $countForm = static function (array $r): void {
      if (!Auth::can('inventory.adjust')) { echo '<span class="muted small">Needs "Restock and adjust stock"</span>'; return; } ?>
      <form method="post" action="<?= e(url('inventory.integrity.correct')) ?>" class="inline-form" data-once data-confirm="Set the stock of <?= e($r['name']) ?> to the counted quantity?"><?= csrf_field() ?>
        <input type="hidden" name="kind" value="count"><input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>">
        <label class="visually-hidden" for="c<?= (int) $r['id'] ?>">Physical count</label><input id="c<?= (int) $r['id'] ?>" name="counted" inputmode="numeric" class="input-sm narrow-input" placeholder="Counted" required>
        <label class="visually-hidden" for="n<?= (int) $r['id'] ?>">Note</label><input id="n<?= (int) $r['id'] ?>" name="note" class="input-sm" placeholder="Note (who counted)" required maxlength="150">
        <button class="btn btn-sm" type="submit">Set count</button></form>
  <?php };
  $phantomForm = static function (array $r): void {
      if (!Auth::can('sales.void')) { echo '<span class="muted small">Needs "Request voids"</span>'; return; } ?>
      <form method="post" action="<?= e(url('inventory.integrity.correct')) ?>" class="inline-form" data-once data-confirm="Mark sale <?= e($r['transaction_no']) ?> as voided WITHOUT changing stock?"><?= csrf_field() ?>
        <input type="hidden" name="kind" value="phantom"><input type="hidden" name="sale_id" value="<?= (int) $r['id'] ?>">
        <input name="note" class="input-sm" placeholder="Reason" required maxlength="150" aria-label="Reason">
        <input name="approver" class="input-sm" placeholder="Approver username" required autocomplete="off" aria-label="Approver username">
        <input name="pin" type="password" inputmode="numeric" class="input-sm narrow-input" placeholder="Void PIN" required autocomplete="off" aria-label="Approver void PIN">
        <button class="btn btn-sm" type="submit">Void record</button></form>
  <?php };
  $section('Negative stock', 'Products whose stock is below zero. Count them physically and enter the real quantity.', $s['negative'], ['sku' => 'SKU', 'name' => 'Product', 'stock_qty' => 'Stock'], $countForm);
  $section('Stock does not match history', 'Current stock differs from the sum of all recorded stock movements. Count the product and enter the real quantity.', $s['ledger'], ['sku' => 'SKU', 'name' => 'Product', 'stock_qty' => 'Stock now', 'ledger_qty' => 'Per history', 'movements' => 'Movements'], $countForm);
  $section('Sales recorded without a stock deduction', 'Usually left behind by a failed sale on non-transactional (MyISAM) tables. If the goods were NOT handed over, void the record (stock is not changed). If they were, leave it and correct the product count instead.', $s['phantom'], ['transaction_no' => 'Transaction', 'created_at' => 'Date', 'product_name' => 'Product', 'quantity' => 'Qty'], $phantomForm);
  $section('Sales that took stock below zero', 'Historical oversales. Shown for review; correct the current count above if needed.', $s['oversold'], ['transaction_no' => 'Transaction', 'created_at' => 'Date', 'name' => 'Product', 'qty_before' => 'Before', 'qty_change' => 'Change', 'qty_after' => 'After']);
  $section('Inconsistent movement records', 'Movements whose "after" is not "before + change". Informational; history is never rewritten.', $s['chain'], ['created_at' => 'Date', 'sku' => 'SKU', 'name' => 'Product', 'movement_type' => 'Type', 'qty_before' => 'Before', 'qty_change' => 'Change', 'qty_after' => 'After']);
  ?>
</div>
