<?php
use App\Core\Auth;
use App\Core\Clock;

$hour = (int) Clock::nowLocal()->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$u = Auth::user();
$name = $u['full_name'] !== '' ? explode(' ', $u['full_name'])[0] : $u['username'];
$today = $d['today'];
$range = $d['range'];
?>
<div class="page">
  <div class="page-head">
    <div>
      <h2 class="page-title"><?= e($greeting . ', ' . $name) ?></h2>
      <p class="muted">Here is what’s happening at <?= e(\App\Core\Settings::get('shop_name')) ?> today.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('pos')) ?>"><?= icon('pos') ?> Open POS</a>
  </div>

  <div class="stat-grid">
    <div class="stat-card">
      <span class="stat-icon tone-blue"><?= icon('receipt') ?></span>
      <div><span class="stat-label">Today’s Sales</span><strong class="stat-value"><?= money($today['net'], true) ?></strong>
      <span class="stat-note">Net of discounts</span></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon tone-green"><?= icon('cash') ?></span>
      <div><span class="stat-label">Transactions Today</span><strong class="stat-value"><?= number_format($today['txn_count']) ?></strong>
      <span class="stat-note"><?= e(plural($today['items'], 'item')) ?> sold</span></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon tone-red"><?= icon('box') ?></span>
      <div><span class="stat-label">Total Products</span><strong class="stat-value"><?= number_format($d['products_total']) ?></strong>
      <span class="stat-note">Active products</span></div>
    </div>
    <a class="stat-card stat-link" href="<?= e(url('products', ['stock' => 'low'])) ?>">
      <span class="stat-icon tone-amber"><?= icon('inventory') ?></span>
      <div><span class="stat-label">Low / Out of Stock</span><strong class="stat-value"><?= number_format($d['low_stock']) ?> <small class="text-danger">/ <?= number_format($d['out_of_stock']) ?></small></strong>
      <span class="stat-note text-accent"><?= ($d['low_stock'] + $d['out_of_stock']) > 0 ? 'Needs attention' : 'All stocked' ?></span></div>
    </a>
  </div>

  <div class="grid-2-1">
    <section class="card" aria-labelledby="overview-title">
      <div class="card-head">
        <div><h3 id="overview-title">Sales Overview</h3><p class="muted small">Net sales for <?= e(\App\Services\ReportService::rangeLabel($from, $to)) ?></p></div>
        <form method="get" class="inline-form" action="">
          <input type="hidden" name="r" value="dashboard">
          <label class="visually-hidden" for="d-from">From</label>
          <input type="date" id="d-from" name="from" value="<?= e($from) ?>" class="input-sm">
          <label class="visually-hidden" for="d-to">To</label>
          <input type="date" id="d-to" name="to" value="<?= e($to) ?>" class="input-sm">
          <button class="btn btn-sm" type="submit">Apply</button>
        </form>
      </div>
      <div class="card-body">
        <strong class="big-number"><?= money($range['net'], true) ?></strong>
        <p class="muted small"><?= e(plural($range['txn_count'], 'transaction')) ?> · gross <?= money($range['gross'], true) ?> · discounts <?= money($range['discounts'], true) ?> · gross profit <?= money($range['profit'], true) ?></p>
        <?php
        $req = \App\Services\ReportService::normalize(['type' => 'sales', 'group' => 'day', 'from' => $from, 'to' => $to]);
        $days = [];
        $cur = new DateTimeImmutable($req['from']);
        $end = new DateTimeImmutable($req['to']);
        $spanDays = (int) $cur->diff($end)->days + 1;
        $groupBy = $spanDays > 62 ? 'month' : 'day';
        $chart = \App\Services\ReportService::build(array_merge($req, ['group' => $groupBy]));
        $byLabel = [];
        foreach ($chart['rows'] as $row) {
            $byLabel[$row['period']] = \App\Core\Money::toCents($row['net']);
        }
        if ($groupBy === 'day' && $spanDays <= 62) {
            for ($dte = $cur; $dte <= $end; $dte = $dte->modify('+1 day')) {
                $label = $dte->format('D, M j, Y');
                $days[] = ['short' => $spanDays <= 7 ? $dte->format('D') : $dte->format('j'), 'full' => $label, 'v' => $byLabel[$label] ?? 0, 'today' => $dte->format('Y-m-d') === Clock::todayLocal()];
            }
        } else {
            foreach ($byLabel as $label => $v) {
                $days[] = ['short' => substr($label, 0, 3), 'full' => $label, 'v' => $v, 'today' => false];
            }
        }
        $max = max(1, ...array_column($days, 'v') ?: [1]);
        ?>
        <?php if ($range['txn_count'] === 0): ?>
          <div class="empty-state compact"><?= icon('reports', 'icon-lg') ?><p>No sales recorded in this period yet.</p></div>
        <?php else: ?>
          <ul class="bar-chart" aria-label="Net sales per period">
            <?php foreach ($days as $day): $pct = (int) round($day['v'] * 100 / $max); ?>
              <li class="<?= $day['today'] ? 'is-today' : '' ?>" title="<?= e($day['full'] . ': ' . money($day['v'], true)) ?>">
                <span class="bar-wrap"><span class="bar h<?= (int) (round($pct / 5) * 5) ?>"></span></span>
                <span class="bar-label"><?= e($day['short']) ?></span>
                <span class="visually-hidden"><?= e($day['full'] . ': ' . money($day['v'], true)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="alerts-title">
      <div class="card-head">
        <div><h3 id="alerts-title">Stock Alerts</h3><p class="muted small">Products that need attention</p></div>
        <a class="link-accent small" href="<?= e(url('products', ['stock' => 'low'])) ?>">View all</a>
      </div>
      <?php if (!$d['low_items']): ?>
        <div class="empty-state compact"><?= icon('check', 'icon-lg') ?><p>No low-stock products.</p></div>
      <?php else: ?>
        <ul class="alert-list">
          <?php foreach ($d['low_items'] as $p): ?>
            <li>
              <span class="thumb <?= tint($p['name']) ?>"><?= e(initials($p['name'])) ?></span>
              <span class="grow"><a href="<?= e(url('products.adjust', ['id' => $p['id']])) ?>"><strong><?= e($p['name']) ?></strong></a><small class="muted"><?= e($p['sku']) ?></small></span>
              <span class="pill <?= (int) $p['stock_qty'] <= 0 ? 'pill-danger' : 'pill-warning' ?>"><?= (int) $p['stock_qty'] <= 0 ? 'Out' : e($p['stock_qty'] . ' left') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <section class="card" aria-labelledby="recent-title">
    <div class="card-head">
      <div><h3 id="recent-title">Recent Transactions</h3><p class="muted small">Latest sales</p></div>
      <a class="link-accent small" href="<?= e(url('sales')) ?>">View all sales <?= icon('arrow-right') ?></a>
    </div>
    <?php if (!$d['recent']): ?>
      <div class="empty-state compact"><?= icon('receipt', 'icon-lg') ?><p>No transactions yet. Completed sales from the POS will appear here.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Transaction</th><th scope="col">Date &amp; time</th><th scope="col" class="num">Items</th><th scope="col" class="num">Total</th><th scope="col">Payment</th><th scope="col">Status</th></tr></thead>
          <tbody>
          <?php foreach ($d['recent'] as $s): ?>
            <tr>
              <td><a class="strong" href="<?= e(url('sales.view', ['id' => $s['id']])) ?>">#<?= e($s['transaction_no']) ?></a></td>
              <td><?= e(local_time($s['created_at'])) ?></td>
              <td class="num"><?= (int) $s['item_count'] ?></td>
              <td class="num"><?= money($s['total']) ?></td>
              <td>Cash</td>
              <td><?php include __DIR__ . '/../partials/status_pill.php'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
