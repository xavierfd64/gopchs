<div class="page">
  <div class="page-head"><div><h2 class="page-title">Settings</h2><p class="muted">Install signed MotoSupply update packages. Installed version: <strong><?= e(MOTO_VERSION) ?></strong>.</p></div></div>
  <?php include __DIR__ . '/../partials/settings_tabs.php'; ?>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
  <?php if ($result): ?>
    <div class="alert <?= $result['ok'] ? 'alert-success' : 'alert-error' ?>" role="status"><?= icon($result['ok'] ? 'check' : 'alert') ?><span><strong><?= $result['ok'] ? 'Update finished.' : 'Update did not complete.' ?></strong>
      <ul class="log-list"><?php foreach ($result['log'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></span></div>
  <?php endif; ?>
  <?php foreach ($blockers as $b): ?><div class="alert alert-info"><?= icon('alert') ?><span><?= e($b) ?></span></div><?php endforeach; ?>
  <?php if ($pending): ?><div class="alert alert-info"><span>Database updates are pending and will be applied automatically on the next page load.</span></div><?php endif; ?>

  <?php if ($summary): $m = $summary['manifest']; ?>
    <section class="card card-pad stack" aria-labelledby="sum-title">
      <h3 id="sum-title">Ready to install version <?= e($summary['version']) ?></h3>
      <dl class="summary-list">
        <div><dt>Current → new version</dt><dd><?= e($summary['from']) ?> → <?= e($summary['version']) ?></dd></div>
        <div><dt>Signature</dt><dd><span class="pill pill-success">Valid: official release key</span></dd></div>
        <div><dt>Files</dt><dd><?= (int) $summary['files'] ?> in package · <?= (int) $summary['changed'] ?> changed · <?= (int) $summary['added'] ?> new<?= $summary['remove'] ? ' · ' . (int) $summary['remove'] . ' removed' : '' ?></dd></div>
        <div><dt>Database updates</dt><dd><?= $summary['migrations'] ? e(implode(', ', array_map('basename', $summary['migrations']))) . ' (adds tables/columns only)' : 'None' ?></dd></div>
        <div><dt>Kept as they are</dt><dd>Configuration, database records, uploads (logo, product images), logs and backups.</dd></div>
      </dl>
      <?php if ($summary['changelog'] !== ''): ?><details><summary>What's new</summary><pre class="changelog"><?= e($summary['changelog']) ?></pre></details><?php endif; ?>
      <form method="post" action="<?= e(url('updates.apply')) ?>" class="stack" data-once>
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e((string) ($_SESSION['_update_token'] ?? '')) ?>">
        <label class="check"><input type="checkbox" name="confirm" value="1" required> I understand that MotoSupply will back up the database and files, enter maintenance mode for a moment and then install the update.</label>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Install update</button></div>
      </form>
    </section>
  <?php endif; ?>

  <section class="card card-pad stack">
    <h3>Upload an update package</h3>
    <p class="small muted">Use the file named <strong>MotoSupply-POS-Update.zip</strong> (not the installer package). Packages are checked for a valid signature, file checksums and safe paths before anything is changed. Maximum size 20 MB (your server's upload limit: <?= e((string) $uploadLimit) ?>).</p>
    <form method="post" action="<?= e(url('updates.upload')) ?>" enctype="multipart/form-data" class="inline-form" data-once>
      <?= csrf_field() ?>
      <label class="visually-hidden" for="package">Update package</label><input id="package" type="file" name="package" accept=".zip,application/zip" required>
      <button class="btn" type="submit"<?= $blockers ? ' disabled' : '' ?>><?= icon('upload') ?> Upload and check</button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><div><h3>Update history and backups</h3><p class="muted small">Each update keeps a database dump and the previous application files. Restoring files does not change the database.</p></div></div>
    <?php if (!$history): ?><div class="empty-state compact"><p>No updates installed through this page yet.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
      <thead><tr><th scope="col">Date</th><th scope="col">Version</th><th scope="col">Result</th><th scope="col">By</th><th scope="col">Backup</th><th scope="col">Recovery</th></tr></thead>
      <tbody><?php foreach ($history as $h): ?><tr>
        <td data-label="Date"><?= e(local_time($h['created_at'])) ?></td>
        <td data-label="Version"><?= e($h['from_version']) ?> → <?= e($h['to_version']) ?></td>
        <td data-label="Result"><span class="pill <?= $h['status'] === 'success' ? 'pill-success' : ($h['status'] === 'failed' ? 'pill-danger' : 'pill-muted') ?>"><?= e(str_replace('_', ' ', ucfirst($h['status']))) ?></span></td>
        <td data-label="By"><?= e($h['username'] ?? '') ?></td>
        <td data-label="Backup"><a href="<?= e(url('updates.backup', ['id' => $h['id'], 'type' => 'database'])) ?>">Database</a> · <a href="<?= e(url('updates.backup', ['id' => $h['id'], 'type' => 'files'])) ?>">Files</a></td>
        <td data-label="Recovery"><?php if ($h['status'] === 'success'): ?>
          <form method="post" action="<?= e(url('updates.rollback')) ?>" class="inline-form" data-confirm="Restore the application files of version <?= e($h['from_version']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $h['id'] ?>"><input type="hidden" name="confirm" value="1"><button class="btn btn-sm" type="submit">Restore previous files</button></form>
        <?php else: ?>—<?php endif; ?></td>
      </tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>
</div>
