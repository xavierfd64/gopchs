<?php $s = $sale; ?>
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <a class="back-link" href="<?= e(url('sales')) ?>">← Sales History</a>
      <h2 class="page-title">#<?= e($sale['transaction_no']) ?> <?php include __DIR__ . '/../partials/status_pill.php'; ?></h2>
      <p class="muted"><?= e(local_time($sale['created_at'], 'l, M j, Y · g:i A')) ?> · Cashier: <?= e($sale['cashier']) ?></p>
    </div>
    <a class="btn" href="<?= e(url('sales.receipt', ['id' => $sale['id'], 'print' => 1])) ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Print receipt</a>
  </div>
  <?php if ($sale['status'] === 'voided'): ?>
    <div class="alert alert-error" role="status"><?= icon('alert') ?><span>Voided on <?= e(local_time($sale['voided_at'])) ?> by <?= e($sale['voided_by_name']) ?><?= $sale['void_approved_by_name'] ? ', approved by ' . e($sale['void_approved_by_name']) : '' ?>. Reason: <?= e($sale['void_reason']) ?></span></div>
  <?php endif; ?>
  <section class="card">
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th scope="col">Product</th><th scope="col">SKU</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Unit price</th><th scope="col" class="num">Line total</th></tr></thead>
        <tbody>
        <?php foreach ($sale['items'] as $it): ?>
          <tr><td><?= e($it['product_name']) ?></td><td><?= e($it['sku']) ?></td><td class="num"><?= (int) $it['quantity'] ?> <?= e($it['unit']) ?></td><td class="num"><?= money($it['unit_price']) ?></td><td class="num"><?= money($it['line_total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <dl class="totals card-pad">
      <div><dt>Subtotal</dt><dd><?= money($sale['subtotal']) ?></dd></div>
      <div><dt>Discount</dt><dd>-<?= money($sale['discount_amount']) ?></dd></div>
      <div class="grand"><dt>Total</dt><dd><?= money($sale['total']) ?></dd></div>
      <div><dt>Cash tendered</dt><dd><?= money($sale['amount_tendered']) ?></dd></div>
      <div><dt>Change</dt><dd><?= money($sale['change_due']) ?></dd></div>
    </dl>
  </section>

  <?php if ($sale['status'] === 'completed' && \App\Core\Auth::can('sales.void')): ?>
  <section class="card card-pad" aria-labelledby="void-title">
    <h3 id="void-title">Void this sale</h3>
    <p class="muted small">The sale stays in the records, marked voided, and is excluded from net sales. All items return to stock. A void needs a reason and approval by a supervisor, who enters their own username and void PIN (not their login password). It cannot be undone or repeated.</p>
    <?php if ($voidError): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($voidError) ?></span></div><?php endif; ?>
    <form method="post" action="<?= e(url('sales.void')) ?>" class="form-grid" data-once data-confirm="Void sale #<?= e($sale['transaction_no']) ?>? Stock will be returned to inventory.">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
      <div class="field span-2">
        <label for="void-reason">Reason <span class="req">*</span></label>
        <input id="void-reason" name="reason" required maxlength="255" placeholder="e.g. Wrong item rung up">
      </div>
      <div class="field">
        <label for="void-approver">Approver username <span class="req">*</span></label>
        <input id="void-approver" name="approver" required autocomplete="off" autocapitalize="none" spellcheck="false">
      </div>
      <div class="field">
        <label for="void-pin">Approver void PIN <span class="req">*</span></label>
        <input id="void-pin" name="pin" type="password" inputmode="numeric" required autocomplete="off" maxlength="12">
      </div>
      <div class="form-actions span-2"><button type="submit" class="btn btn-danger">Void sale</button></div>
    </form>
  </section>
  <?php endif; ?>
</div>
