<div class="page page-narrow">
  <div class="page-head">
    <div>
      <a class="back-link" href="<?= e(url('settings')) ?>">← Settings</a>
      <h2 class="page-title">System check</h2>
      <p class="muted">Server environment diagnostics. Visible only to logged-in administrators.</p>
    </div>
  </div>
  <section class="card">
    <div class="card-head">
      <div><h3>Server requirements</h3><p class="muted small">Folders are prepared automatically and checked with a real write test.</p></div>
      <a class="btn btn-sm" href="<?= e(url('settings.system')) ?>">Recheck Requirements</a>
    </div>
    <?php include MOTO_ROOT . '/app/views/partials/requirements_table.php'; ?>
    <p class="muted small card-pad">Application version <?= e(MOTO_VERSION) ?> · database schema <?= e((string) MOTO_SCHEMA_VERSION) ?></p>
  </section>

  <section class="card card-pad stack" id="https" aria-labelledby="https-title">
    <h3 id="https-title">HTTPS and security mode</h3>
    <div class="mode-box">
      <div>
        <p>Connection: <?= $https ? '<span class="pill pill-success">HTTPS active</span>' : '<span class="pill pill-warning">Not encrypted (HTTP)</span>' ?></p>
        <p class="small muted">Mode: <strong><?= $mode === 'production' ? 'Production (HTTPS required)' : 'Testing (HTTP allowed with a warning)' ?></strong><?= is_bool($override) ? ' · overridden in the configuration file (force_https = ' . ($override ? 'true' : 'false') . ')' : '' ?></p>
      </div>
      <form method="post" action="<?= e(url('settings.security')) ?>" data-once<?= $mode === 'production' ? ' data-confirm="Allow plain HTTP again? Only do this for testing."' : '' ?>>
        <?= csrf_field() ?>
        <?php if ($mode === 'production'): ?>
          <input type="hidden" name="mode" value="testing">
          <button type="submit" class="btn">Switch back to testing mode</button>
        <?php else: ?>
          <input type="hidden" name="mode" value="production">
          <button type="submit" class="btn btn-primary"<?= $https ? '' : ' disabled aria-describedby="https-help"' ?>>Require HTTPS (production)</button>
        <?php endif; ?>
      </form>
    </div>
    <?php if (!$https): ?>
    <div id="https-help" class="small">
      <p><strong>How to enable HTTPS</strong></p>
      <ol>
        <li>Install the free SSL certificate in your hosting panel. <em>InfinityFree:</em> Client Area → your account → <em>Free SSL Certificates</em> → request a certificate for your domain and follow the verification steps (it can take a while to become active). <em>cPanel:</em> <em>SSL/TLS Status</em> → <em>Run AutoSSL</em>.</li>
        <li>Open this page using <code>https://</code> instead of <code>http://</code>.</li>
        <li>Click <strong>Require HTTPS (production)</strong>. From then on, all visitors are redirected to HTTPS. The button only works over HTTPS, so you cannot lock yourself out.</li>
      </ol>
      <?php if ($proxyHint): ?><p class="alert alert-info"><span>Your host seems to handle SSL with a proxy. See “HTTPS behind a proxy” in README.md so MotoSupply can detect HTTPS.</span></p><?php endif; ?>
    </div>
    <?php endif; ?>
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
