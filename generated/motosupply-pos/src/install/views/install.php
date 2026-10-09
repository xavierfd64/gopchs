<?php defined('MOTO_ROOT') || exit;
$db = $w['db']; $shop = $w['shop']; $admin = $w['admin'];
?>
<h1>Ready to install</h1>
<p class="lead">Check the details below, then click <strong>Install MotoSupply</strong>. It usually takes a few seconds; please do not close the page.</p>
<dl class="summary-list">
  <div><dt>Database</dt><dd><?= e($db['name']) ?> on <?= e($db['host']) ?><?= (int) $db['port'] !== 3306 ? ':' . (int) $db['port'] : '' ?> (user <?= e($db['user']) ?>)</dd></div>
  <div><dt>Shop</dt><dd><?= e($shop['shop_name']) ?><?= $shop['shop_address'] !== '' ? ', ' . e($shop['shop_address']) : '' ?></dd></div>
  <div><dt>Timezone / currency</dt><dd><?= e($shop['timezone']) ?> · <?= e(Installer::CURRENCIES[$shop['currency_code']][0]) ?></dd></div>
  <div><dt>Administrator</dt><dd><?= e($admin['username']) ?></dd></div>
  <div><dt>Security mode</dt><dd><?= \App\Core\Http::isHttps() ? 'Production: HTTPS required' : 'Testing: HTTP allowed with a warning on every page' ?></dd></div>
</dl>
<?php if (!\App\Core\Http::isHttps()): ?>
  <div class="alert alert-error"><span><strong>Not secure (HTTP).</strong> This is a test installation. Do not use real passwords or business data until you install the free SSL certificate, open the site with <strong>https://</strong> and turn on <em>Require HTTPS</em> in Settings → System Check.</span></div>
<?php endif; ?>
<form method="post" action="<?= e(wizard_url('install')) ?>" class="wizard-actions" data-once>
  <?= csrf_field() ?>
  <a class="btn" href="<?= e(wizard_url('admin')) ?>">Back</a>
  <button type="submit" class="btn btn-primary btn-lg">Install MotoSupply</button>
</form>
