<?php
use App\Services\ProductService;
$exportBase = ['type' => 'inventory'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <h2 class="page-title">Products / Inventory</h2>
      <p class="muted">Manage products, pricing, and stock levels.</p>
    </div>
    <div class="btn-row">
      <a class="btn" href="<?= e(url('reports', $exportBase)) ?>"><?= icon('reports') ?> Inventory Report</a>
      <a class="btn" href="<?= e(url('reports.export', $exportBase + ['format' => 'csv'])) ?>"><?= icon('download') ?> Export CSV</a>
      <a class="btn btn-primary" href="<?= e(url('products.create')) ?>"><?= icon('plus') ?> Add Product</a>
    </div>
  </div>

  <div class="stat-strip">
    <div><span class="stat-label">Total Products</span><strong class="stat-value"><?= number_format((int) $stats['total']) ?></strong><small class="muted">Across <?= (int) $stats['categories'] ?> categories</small></div>
    <div><span class="stat-label">Inventory Value</span><strong class="stat-value"><?= money((string) $stats['value']) ?></strong><small class="muted">At current cost</small></div>
    <a href="<?= e(url('products', ['stock' => 'low'])) ?>"><span class="stat-label">Low Stock</span><strong class="stat-value text-warning"><?= number_format((int) $stats['low']) ?></strong><small class="muted">At or below reorder level</small></a>
    <a href="<?= e(url('products', ['stock' => 'out'])) ?>"><span class="stat-label">Out of Stock</span><strong class="stat-value text-danger"><?= number_format((int) $stats['out_of_stock']) ?></strong><small class="muted">Requires action</small></a>
  </div>

  <section class="card">
    <form class="toolbar" method="get" action="">
      <input type="hidden" name="r" value="products">
      <div class="search-input">
        <?= icon('search') ?>
        <label class="visually-hidden" for="p-q">Search products</label>
        <input id="p-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search products, SKU, barcode…">
      </div>
      <div class="toolbar-filters">
        <label class="visually-hidden" for="p-cat">Category</label>
        <select id="p-cat" name="category" data-autosubmit>
          <option value="">All categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= (int) $f['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="p-stock">Stock status</label>
        <select id="p-stock" name="stock" data-autosubmit>
          <option value="">Any stock status</option>
          <option value="in"<?= $f['stock'] === 'in' ? ' selected' : '' ?>>In stock</option>
          <option value="low"<?= $f['stock'] === 'low' ? ' selected' : '' ?>>Low stock</option>
          <option value="out"<?= $f['stock'] === 'out' ? ' selected' : '' ?>>Out of stock</option>
        </select>
        <label class="visually-hidden" for="p-status">Product status</label>
        <select id="p-status" name="status" data-autosubmit>
          <option value="active"<?= $f['status'] === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="archived"<?= $f['status'] === 'archived' ? ' selected' : '' ?>>Archived</option>
          <option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>>All</option>
        </select>
        <label class="visually-hidden" for="p-sort">Sort by</label>
        <select id="p-sort" name="sort" data-autosubmit>
          <?php foreach (['name_asc' => 'Name A–Z', 'name_desc' => 'Name Z–A', 'sku_asc' => 'SKU', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'stock_asc' => 'Stock: low to high', 'stock_desc' => 'Stock: high to low', 'updated_desc' => 'Recently updated'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= $f['sort'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Apply</button>
      </div>
    </form>

    <?php if (!$list['rows']): ?>
      <div class="empty-state">
        <?= icon('box', 'icon-lg') ?>
        <?php if ($f['q'] !== '' || $f['stock'] !== '' || $f['category_id'] || $f['status'] !== 'active'): ?>
          <h3>No matching products</h3><p>Try a different search or clear the filters.</p>
          <a class="btn" href="<?= e(url('products')) ?>">Clear filters</a>
        <?php else: ?>
          <h3>No products yet</h3><p>Add your first product to start selling.</p>
          <a class="btn btn-primary" href="<?= e(url('products.create')) ?>"><?= icon('plus') ?> Add Product</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th scope="col">Product</th><th scope="col">SKU / Barcode</th><th scope="col">Category</th>
            <th scope="col" class="num">Cost</th><th scope="col" class="num">Price</th><th scope="col" class="num">Stock</th>
            <th scope="col" class="num">Reorder</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr></thead>
          <tbody>
          <?php foreach ($list['rows'] as $p): $st = ProductService::stockStatus($p); ?>
            <tr class="<?= (int) $p['is_active'] === 0 ? 'is-archived' : '' ?>">
              <td>
                <div class="product-cell">
                  <?php if ($p['image_path']): ?>
                    <img class="thumb" src="<?= e(\App\Core\Http::basePath() . '/' . $p['image_path']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <span class="thumb <?= tint($p['name']) ?>" aria-hidden="true"><?= e(initials($p['name'])) ?></span>
                  <?php endif; ?>
                  <span><a class="strong" href="<?= e(url('products.edit', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a><small class="muted"><?= e($p['unit']) ?></small></span>
                </div>
              </td>
              <td><strong><?= e($p['sku']) ?></strong><small class="muted block"><?= e($p['barcode'] ?? '') ?></small></td>
              <td><?= e($p['category_name'] ?? '—') ?></td>
              <td class="num muted"><?= money($p['cost_price']) ?></td>
              <td class="num strong"><?= money($p['selling_price']) ?></td>
              <td class="num <?= $st === 'out' ? 'text-danger' : ($st === 'low' ? 'text-warning' : '') ?>"><?= number_format((int) $p['stock_qty']) ?></td>
              <td class="num muted"><?= (int) $p['effective_threshold'] ?></td>
              <td>
                <?php if ((int) $p['is_active'] === 0): ?><span class="pill pill-muted">Archived</span>
                <?php elseif ($st === 'out'): ?><span class="pill pill-danger">Out of Stock</span>
                <?php elseif ($st === 'low'): ?><span class="pill pill-warning">Low Stock</span>
                <?php else: ?><span class="pill pill-success">In Stock</span><?php endif; ?>
              </td>
              <td class="actions">
                <details class="menu">
                  <summary class="icon-btn" aria-label="Actions for <?= e($p['name']) ?>"><?= icon('more') ?></summary>
                  <div class="menu-list">
                    <a href="<?= e(url('products.edit', ['id' => $p['id']])) ?>"><?= icon('edit') ?> Edit</a>
                    <a href="<?= e(url('products.adjust', ['id' => $p['id']])) ?>"><?= icon('adjust') ?> Adjust stock</a>
                    <a href="<?= e(url('products.movements', ['id' => $p['id']])) ?>"><?= icon('history') ?> Stock history</a>
                    <?php if ((int) $p['is_active'] === 1): ?>
                      <form method="post" action="<?= e(url('products.archive')) ?>" data-confirm="Archive “<?= e($p['name']) ?>”? It will no longer be available in the POS.">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button type="submit"><?= icon('archive') ?> Archive</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="<?= e(url('products.restore')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button type="submit"><?= icon('restore') ?> Restore</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($list['page'], $list['pages'], $list['total'], 'products', ProductService::PER_PAGE, count($list['rows'])) ?>
    <?php endif; ?>
  </section>
</div>
