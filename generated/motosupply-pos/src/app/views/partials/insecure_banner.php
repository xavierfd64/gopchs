<?php if (!\App\Core\Http::isHttps()): ?>
<div class="insecure-banner" role="alert">
  <?= icon('alert') ?>
  <span><strong>Not secure. Testing mode only.</strong> This connection is not encrypted (HTTP). Do not use real passwords or business data until HTTPS is active.
  <?php if (isset($_SESSION['user_id'])): ?><a href="<?= e(url('settings.system')) ?>#https">How to enable HTTPS</a><?php endif; ?></span>
</div>
<?php endif; ?>
