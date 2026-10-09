<?php
$exportParams = ['type' => 'transactions', 'format' => 'csv'];
if ($f['from'] !== '') {
    $exportParams += ['from' => $f['from'], 'to' => $f['to']];
} else {
    $exportParams += ['preset' => 'month'];
}
?>
<div class="page">
  <div class="page-head">
    <div>
      <h2 class="page-title">Sales History</h2>
      <p class="muted">Review transactions and print receipt copies.</p>
    </div>
    <a class="btn" href="<?= e(url('reports.export', $exportParams)) ?>"><?= icon('download') ?> Export Sales</a>
  </div>
  <section class="card">
    <form class="toolbar" method="get" action="">
      <input type="hidden" name="r" value="sales">
      <div class="search-input">
        <?= icon('search') ?>
        <label class="visually-hidden" for="s-q">Search transaction number or product</label>
        <input id="s-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search transaction number or product…">
      </div>
      <div class="toolbar-filters">
        <label class="inline-label" for="s-from">From</label>
        <input id="s-from" type="date" name="from" value="<?= e($f['from']) ?>">
        <label class="inline-label" for="s-to">To</label>
        <input id="s-to" type="date" name="to" value="<?= e($f['to']) ?>">
        <label class="visually-hidden" for="s-status">Status</label>
        <select id="s-status" name="status">
          <option value="">All statuses</option>
          <option value="completed"<?= $f['status'] === 'completed' ? ' selected' : '' ?>>Paid</option>
          <option value="voided"<?= $f['status'] === 'voided' ? ' selected' : '' ?>>Voided</option>
        </select>
        <button type="submit" class="btn">Apply</button>
        <?php if ($f['q'] !== '' || $f['from'] !== '' || $f['status'] !== ''): ?><a class="btn btn-ghost" href="<?= e(url('sales')) ?>">Reset</a><?php endif; ?>
      </div>
    </form>
    <?php if (!$list['rows']): ?>
      <div class="empty-state"><?= icon('receipt', 'icon-lg') ?>
        <h3><?= $f['q'] !== '' || $f['from'] !== '' || $f['status'] !== '' ? 'No matching transactions' : 'No sales yet' ?></h3>
        <p>Completed sales from the POS appear here.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table-cards">
          <thead><tr><th scope="col">Transaction number</th><th scope="col">Date and time</th><th scope="col" class="num">Items</th><th scope="col" class="num">Total</th><th scope="col">Payment method</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
          <tbody>
          <?php foreach ($list['rows'] as $s): ?>
            <tr>
              <td><a class="strong" href="<?= e(url('sales.view', ['id' => $s['id']])) ?>">#<?= e($s['transaction_no']) ?></a></td>
              <td><?= e(local_time($s['created_at'], 'M j, Y · g:i A')) ?></td>
              <td class="num"><?= (int) $s['item_count'] ?></td>
              <td class="num"><?= money($s['total']) ?></td>
              <td><?= e(ucfirst($s['payment_method'])) ?></td>
              <td><?php include __DIR__ . '/../partials/status_pill.php'; ?></td>
              <td><a class="strong" href="<?= e(url('sales.view', ['id' => $s['id']])) ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($list['page'], $list['pages'], $list['total'], 'transactions', \App\Services\SaleService::PER_PAGE, count($list['rows'])) ?>
    <?php endif; ?>
  </section>
</div>
