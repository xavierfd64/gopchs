<?php use App\Services\ProductImport; ?>
<div class="page">
  <div class="page-head"><div>
    <a class="back-link" href="<?= e(url('products')) ?>">← Inventory</a>
    <h2 class="page-title">Import products from CSV</h2>
    <p class="muted">Create many products at once from a spreadsheet saved as CSV.</p>
  </div>
  <div class="btn-row">
    <a class="btn" href="<?= e(url('products.import.template', ['example' => 1])) ?>"><?= icon('download') ?> Template with example</a>
    <a class="btn" href="<?= e(url('products.import.template')) ?>"><?= icon('download') ?> Blank template</a>
  </div></div>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

  <?php if ($result): ?>
    <section class="card card-pad stack" aria-labelledby="res-title">
      <h3 id="res-title">Import finished</h3>
      <div class="summary-grid">
        <div><span class="stat-label">Created</span><strong><?= (int) $result['created'] ?></strong></div>
        <div><span class="stat-label">Updated</span><strong><?= (int) $result['updated'] ?></strong></div>
        <div><span class="stat-label">Skipped</span><strong><?= (int) $result['skipped'] ?></strong></div>
        <div><span class="stat-label">Rows with errors</span><strong><?= (int) $result['failed'] ?></strong></div>
      </div>
      <?php if ($result['not_imported']): ?>
        <details><summary>Rows not imported (<?= count($result['not_imported']) ?>)</summary>
          <ul class="log-list"><?php foreach ($result['not_imported'] as $r): ?><li>Line <?= (int) $r['line'] ?> <?= e($r['sku']) ?>: <?= e($r['reason']) ?></li><?php endforeach; ?></ul></details>
      <?php endif; ?>
      <a class="btn" href="<?= e(url('products')) ?>">Go to inventory</a>
    </section>
  <?php endif; ?>

  <?php if (!$analysis): ?>
  <section class="card card-pad stack">
    <h3>1. Choose a file</h3>
    <form method="post" action="<?= e(url('products.import')) ?>" enctype="multipart/form-data" class="stack" data-once>
      <?= csrf_field() ?>
      <div class="field"><label for="file">CSV file (UTF-8, up to 2 MB, <?= ProductImport::MAX_ROWS ?> rows)</label><input id="file" type="file" name="file" accept=".csv,text/csv" required></div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('upload') ?> Upload and preview</button></div>
    </form>
    <details class="small"><summary>Columns and rules</summary>
      <ul><?php foreach (ProductImport::COLUMNS as $k => $label): ?><li><code><?= e($k) ?></code>: <?= e($label) ?></li><?php endforeach; ?></ul>
      <p>Prices use a dot for decimals (450.00). The row with SKU starting <code>EXAMPLE-</code> in the template is always skipped. Stock of existing products is never changed by an import; use Adjust stock.</p>
    </details>
  </section>
  <?php else: $c = $analysis['counts']; ?>
  <section class="card card-pad stack">
    <h3>2. Review</h3>
    <form method="get" action="" class="inline-form">
      <input type="hidden" name="r" value="products.import"><input type="hidden" name="token" value="<?= e($token) ?>">
      <span class="label">When a SKU already exists:</span>
      <label class="radio"><input type="radio" name="mode" value="create"<?= $mode === 'create' ? ' checked' : '' ?> data-autosubmit> Skip it (create new products only)</label>
      <label class="radio"><input type="radio" name="mode" value="update"<?= $mode === 'update' ? ' checked' : '' ?> data-autosubmit> Update its details and prices (stock unchanged)</label>
      <noscript><button class="btn btn-sm" type="submit">Apply</button></noscript>
    </form>
    <div class="summary-grid">
      <div><span class="stat-label">New products</span><strong class="text-success"><?= $c['create'] ?></strong></div>
      <div><span class="stat-label">Existing to update</span><strong class="<?= $c['update'] ? 'text-warning' : '' ?>"><?= $c['update'] ?></strong></div>
      <div><span class="stat-label">Skipped</span><strong><?= $c['skip'] ?></strong></div>
      <div><span class="stat-label">Rows with errors</span><strong class="<?= $c['error'] ? 'text-danger' : '' ?>"><?= $c['error'] ?></strong></div>
    </div>
    <div class="table-wrap"><table class="table table-cards">
      <thead><tr><th scope="col">Line</th><th scope="col">Action</th><th scope="col">SKU</th><th scope="col">Name</th><th scope="col" class="num">Price</th><th scope="col" class="num">Stock</th><th scope="col">Changes / problems</th></tr></thead>
      <tbody><?php foreach (array_slice($analysis['rows'], 0, 200) as $r): ?>
        <tr><td data-label="Line"><?= (int) $r['line'] ?></td>
          <td data-label="Action"><span class="pill <?= ['create' => 'pill-success', 'update' => 'pill-warning', 'skip' => 'pill-muted', 'error' => 'pill-danger'][$r['action']] ?>"><?= e(ucfirst($r['action'])) ?></span></td>
          <td data-label="SKU"><?= e($r['input']['sku'] ?? '') ?></td><td data-label="Name"><?= e($r['input']['name'] ?? '') ?></td>
          <td data-label="Price" class="num"><?= e($r['input']['selling_price'] ?? '') ?></td><td data-label="Stock" class="num"><?= $r['action'] === 'update' ? '<span class="muted">unchanged</span>' : e($r['input']['stock_qty'] ?? '0') ?></td>
          <td data-label="Changes / problems" class="small"><?php if ($r['action'] === 'update'): ?><ul class="change-list"><?php foreach ($r['changes'] as $f => [$old, $new]): ?><li><strong><?= e(\App\Services\ProductImport::COLUMNS[$f] ?? $f) ?>:</strong> <span class="muted"><?= e($old === '' ? '(blank)' : $old) ?></span> → <?= e($new === '' ? '(blank)' : $new) ?></li><?php endforeach; ?></ul><?php else: ?><?= e(implode('; ', $r['errors'])) ?><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php if (count($analysis['rows']) > 200): ?><p class="small muted">Showing the first 200 of <?= count($analysis['rows']) ?> rows; the counts above include all rows.</p><?php endif; ?>
  </section>
  <form class="card card-pad stack" method="post" action="<?= e(url('products.import.confirm')) ?>" data-once>
    <?= csrf_field() ?>
    <h3>3. Confirm</h3>
    <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="mode" value="<?= e($mode) ?>">
    <input type="hidden" name="expect" value="<?= "{$c['create']}:{$c['update']}:{$c['skip']}:{$c['error']}" ?>">
    <?php if ($c['update'] > 0): ?><label class="check"><input type="checkbox" name="confirm_update" value="1" required> I confirm that <?= $c['update'] ?> existing product(s) will have their details and prices changed.</label><?php endif; ?>
    <p class="small muted">All valid rows are imported together in one step. If anything goes wrong, nothing is imported.</p>
    <div class="form-actions"><a class="btn" href="<?= e(url('products.import')) ?>">Choose another file</a>
      <button class="btn btn-primary" type="submit"<?= $c['create'] + $c['update'] === 0 ? ' disabled' : '' ?>>Import <?= $c['create'] + $c['update'] ?> product(s)</button></div>
  </form>
  <?php endif; ?>
</div>
