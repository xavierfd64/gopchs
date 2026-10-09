<div class="page">
  <div class="page-head"><div><h2 class="page-title">Settings</h2><p class="muted">Audit log of sensitive actions. Records cannot be edited or deleted here.</p></div></div>
  <?php include __DIR__ . '/../partials/settings_tabs.php'; ?>
  <section class="card">
    <form class="toolbar" method="get" action="">
      <input type="hidden" name="r" value="audit">
      <div class="toolbar-filters">
        <label class="visually-hidden" for="a-action">Action</label><input id="a-action" name="action" value="<?= e($f['action']) ?>" placeholder="Action (e.g. sale.void)">
        <label class="visually-hidden" for="a-user">User</label><input id="a-user" name="user" value="<?= e($f['user']) ?>" placeholder="Username">
        <label class="visually-hidden" for="a-status">Status</label>
        <select id="a-status" name="status"><option value="">All results</option><option value="success"<?= $f['status'] === 'success' ? ' selected' : '' ?>>Success</option><option value="failure"<?= $f['status'] === 'failure' ? ' selected' : '' ?>>Failure</option></select>
        <label class="inline-label" for="a-from">From</label><input id="a-from" type="date" name="from" value="<?= e($f['from']) ?>">
        <label class="inline-label" for="a-to">To</label><input id="a-to" type="date" name="to" value="<?= e($f['to']) ?>">
        <button class="btn" type="submit">Filter</button>
      </div>
    </form>
    <?php if (!$list['rows']): ?>
      <div class="empty-state"><?= icon('history', 'icon-lg') ?><p>No audit records match.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th scope="col">Time</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $a): ?>
          <tr>
            <td data-label="Time"><?= e(local_time($a['created_at'], 'M j, Y g:i:s A')) ?></td>
            <td data-label="User"><?= e($a['username'] ?: '—') ?><small class="muted block"><?= e($a['ip_address']) ?></small></td>
            <td data-label="Action"><code><?= e($a['action']) ?></code></td>
            <td data-label="Record"><?= e(trim($a['entity_type'] . ' ' . $a['entity_id'])) ?></td>
            <td data-label="Result"><?= $a['status'] === 'success' ? '<span class="pill pill-success">Success</span>' : '<span class="pill pill-danger">Failure</span>' ?></td>
            <td data-label="Details" class="audit-details"><?= e((string) $a['details']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination($list['page'], $list['pages'], $list['total'], 'records', 50, count($list['rows'])) ?>
    <?php endif; ?>
  </section>
</div>
