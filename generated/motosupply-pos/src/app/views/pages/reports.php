<?php
use App\Services\ReportExporter;
use App\Services\ReportService;

$base = ['type' => $req['type'], 'group' => $req['group'], 'from' => $req['from'], 'to' => $req['to']];
$dated = in_array($req['type'], ReportService::DATED, true);
$library = [
    ['sales', 'day', 'Daily Sales', 'Sales, discounts, net sales and profit per day.', 'receipt'],
    ['sales', 'week', 'Weekly Sales', 'Sales totals grouped by week (Monday start).', 'reports'],
    ['sales', 'month', 'Monthly Sales', 'Monthly revenue, discounts and transaction totals.', 'reports'],
    ['transactions', 'day', 'Transactions', 'Every sale in the period, including voids.', 'receipt'],
    ['product_sales', 'day', 'Sales by Product', 'Quantities sold and revenue per product.', 'box'],
    ['inventory', 'day', 'Inventory Valuation', 'Current stock value based on cost and selling price.', 'inventory'],
    ['low_stock', 'day', 'Low Stock', 'Products at or below their reorder level.', 'alert'],
    ['out_of_stock', 'day', 'Out of Stock', 'Active products with no stock left.', 'archive'],
    ['movements', 'day', 'Stock Movement', 'Inventory additions and deductions with reasons.', 'history'],
];
$sym = \App\Core\Settings::get('currency_symbol', '₱');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h2 class="page-title">Reports</h2>
      <p class="muted">Analyze store performance and export business data.</p>
    </div>
  </div>

  <form class="card card-pad filter-bar" method="get" action="">
    <input type="hidden" name="r" value="reports">
    <input type="hidden" name="type" value="<?= e($req['type']) ?>">
    <div class="field">
      <label for="r-from">From</label>
      <input id="r-from" type="date" name="from" value="<?= e($req['from']) ?>"<?= $dated ? '' : ' disabled' ?>>
    </div>
    <div class="field">
      <label for="r-to">To</label>
      <input id="r-to" type="date" name="to" value="<?= e($req['to']) ?>"<?= $dated ? '' : ' disabled' ?>>
    </div>
    <?php if ($req['type'] === 'sales'): ?>
    <div class="field">
      <label for="r-group">Group by</label>
      <select id="r-group" name="group">
        <?php foreach (ReportService::GROUPS as $k => $label): ?><option value="<?= $k ?>"<?= $req['group'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field">
      <span class="label">Quick ranges</span>
      <div class="btn-row">
        <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'year' => 'This year'] as $k => $label): ?>
          <a class="btn btn-sm<?= $req['preset'] === $k ? ' is-selected' : '' ?>" href="<?= e(url('reports', ['type' => $req['type'], 'group' => $req['group'], 'preset' => $k])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <button type="submit" class="btn btn-primary"<?= $dated ? '' : ' disabled' ?>>Apply Filters</button>
  </form>

  <h3 class="section-title">Report Library</h3>
  <div class="library">
    <?php foreach ($library as [$type, $group, $name, $desc, $ic]):
        $active = $req['type'] === $type && ($type !== 'sales' || $req['group'] === $group); ?>
      <a class="library-card<?= $active ? ' is-active' : '' ?>" href="<?= e(url('reports', ['type' => $type, 'group' => $group, 'from' => $req['from'], 'to' => $req['to']])) ?>"<?= $active ? ' aria-current="true"' : '' ?>>
        <span class="stat-icon tone-red"><?= icon($ic) ?></span>
        <strong><?= e($name) ?></strong>
        <span class="muted small"><?= e($desc) ?></span>
        <span class="link-accent small">Open report <?= icon('arrow-right') ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <section class="card" aria-labelledby="report-title">
    <div class="card-head">
      <div>
        <h3 id="report-title"><?= e(\App\Core\Settings::get('shop_name')) ?> · <?= e($report['title']) ?></h3>
        <p class="muted small">Reporting period: <?= e($report['range']) ?></p>
      </div>
      <div class="btn-row">
        <a class="btn btn-sm" href="<?= e(url('reports.export', $base + ['format' => 'csv'])) ?>"><?= icon('download') ?> Export CSV</a>
        <a class="btn btn-sm" href="<?= e(url('reports.export', $base + ['format' => 'pdf'])) ?>"><?= icon('download') ?> Download PDF</a>
      </div>
    </div>
    <?php if ($report['summary']): ?>
      <div class="summary-grid">
        <?php foreach ($report['summary'] as [$label, $value, $type]): ?>
          <div><span class="stat-label"><?= e($label) ?></span><strong><?= e(ReportExporter::display($value, $type, $sym)) ?></strong></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (!$report['rows']): ?>
      <div class="empty-state compact"><?= icon('reports', 'icon-lg') ?><p>No records for the selected period.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <?php foreach ($report['columns'] as [, $label, $type]): ?><th scope="col"<?= in_array($type, ['money', 'int'], true) ? ' class="num"' : '' ?>><?= e($label) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr><?php foreach ($report['columns'] as [$key, , $type]): ?><td<?= in_array($type, ['money', 'int'], true) ? ' class="num"' : '' ?>><?= e(ReportExporter::display($row[$key] ?? null, $type, $sym)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($page, $pages, $total, 'rows', 50, count($rows)) ?>
    <?php endif; ?>
    <?php foreach ($report['notes'] as $note): ?><p class="muted small card-pad note"><?= e($note) ?></p><?php endforeach; ?>
  </section>
</div>
