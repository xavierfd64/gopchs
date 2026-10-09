<section class="auth-card" aria-labelledby="hp-title">
  <h1 id="hp-title">Secure connection problem</h1>
  <p>This site is set to <strong>require HTTPS</strong>, but the server could not confirm a secure connection. To avoid an endless redirect, MotoSupply stopped here.</p>
  <?php if ($proxy): ?>
    <p>Your hosting uses a proxy (for example a CDN) that handles SSL. MotoSupply does not trust proxy headers until you list the proxy in the configuration file.</p>
  <?php endif; ?>
  <p><strong>How to fix it</strong> (needs your hosting File Manager or phpMyAdmin):</p>
  <ol>
    <li>Make sure the free SSL certificate is installed and <code>https://</code> opens your site.</li>
    <li>If your host uses a proxy for SSL, follow “HTTPS behind a proxy” in README.md.</li>
    <li>To get back in over HTTP temporarily, open phpMyAdmin, table <code>settings</code>, and set <code>security_mode</code> to <code>testing</code>.</li>
  </ol>
</section>
