<div class="page page-narrow">
  <div class="page-head">
    <div>
      <a class="back-link" href="<?= e(url('settings')) ?>">← Settings</a>
      <h2 class="page-title">System check</h2>
      <p class="muted">Server environment diagnostics. Visible only to logged-in administrators.</p>
    </div>
  </div>
  <section class="card">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead>
        <tbody>
        <?php foreach ($checks as [$label, $status, $detail, $fix]): ?>
          <tr><td><?= e($label) ?></td>
            <td><?= $status === 'pass' ? '<span class="pill pill-success">Passed</span>' : ($status === 'warn' ? '<span class="pill pill-warning">Warning</span>' : '<span class="pill pill-danger">Failed</span>') ?></td>
            <td><?= e($detail) ?><?php if ($fix !== ''): ?><small class="muted block"><?= e($fix) ?></small><?php endif; ?></td></tr>
        <?php endforeach; ?>
          <tr><td>Application version</td><td><span class="pill pill-muted"><?= e(MOTO_VERSION) ?></span></td><td>Database schema <?= e((string) MOTO_SCHEMA_VERSION) ?></td></tr>
        </tbody>
      </table>
    </div>
  </section>
  <section class="card card-pad">
    <h3>Database updates</h3>
    <?php if (!$pending): ?>
      <p class="muted">The database schema is up to date.</p>
    <?php else: ?>
      <p>There are <?= count($pending) ?> pending database update(s) from a newer release. Back up your database before applying them.</p>
      <form method="post" action="<?= e(url('settings.migrate')) ?>" data-once data-confirm="Apply database updates now? Make sure you have a backup.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary">Apply database updates</button>
      </form>
    <?php endif; ?>
  </section>
</div>
