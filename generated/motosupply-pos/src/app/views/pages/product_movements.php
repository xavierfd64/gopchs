<div class="page">
  <div class="page-head">
    <div>
      <a class="back-link" href="<?= e(url('products')) ?>">← Products</a>
      <h2 class="page-title">Stock history</h2>
      <p class="muted"><?= e($product['name']) ?> · <?= e($product['sku']) ?> · current stock <strong><?= number_format((int) $product['stock_qty']) ?></strong></p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('products.adjust', ['id' => $product['id']])) ?>"><?= icon('adjust') ?> Adjust stock</a>
  </div>
  <section class="card">
    <?php if (!$list['rows']): ?>
      <div class="empty-state"><?= icon('history', 'icon-lg') ?><p>No stock movements recorded for this product yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Date &amp; time</th><th scope="col">Type</th><th scope="col" class="num">Before</th><th scope="col" class="num">Change</th><th scope="col" class="num">After</th><th scope="col">Reason / reference</th><th scope="col">User</th></tr></thead>
          <tbody>
          <?php foreach ($list['rows'] as $m): ?>
            <tr>
              <td><?= e(local_time($m['created_at'])) ?></td>
              <td><span class="pill pill-muted"><?= e(ucfirst($m['movement_type'])) ?></span></td>
              <td class="num"><?= (int) $m['qty_before'] ?></td>
              <td class="num <?= (int) $m['qty_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['qty_change'] > 0 ? '+' : '' ?><?= (int) $m['qty_change'] ?></td>
              <td class="num"><?= (int) $m['qty_after'] ?></td>
              <td><?= e($m['reason']) ?><?php if ($m['transaction_no']): ?> · <a href="<?= e(url('sales.view', ['id' => $m['sale_id']])) ?>"><?= e($m['transaction_no']) ?></a><?php endif; ?></td>
              <td><?= e($m['username']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($list['page'], $list['pages'], $list['total'], 'movements', 25, count($list['rows'])) ?>
    <?php endif; ?>
  </section>
</div>
