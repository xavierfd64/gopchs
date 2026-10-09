<?php defined('MOTO_ROOT') || exit;
use App\Core\Http;
use App\Services\Requirements;
$failed = Requirements::hasFailures($checks);
$warned = in_array(Requirements::WARN, array_column($checks, 'status'), true);
$https = Http::isHttps();
?>
<h1>System requirements</h1>
<p class="lead">
  <?php if ($failed): ?>Some items <strong>failed</strong> and must be fixed before installing. Each one says what to do.
  <?php elseif ($warned): ?>Everything required is in place. Items marked <strong>Warning</strong> are worth fixing, but they do not block installation.
  <?php else: ?>Everything is in place.<?php endif; ?>
  Missing folders were created and permissions were corrected automatically where your hosting allows it.
</p>
<?php include MOTO_ROOT . '/app/views/partials/requirements_table.php'; ?>
<form method="post" action="<?= e(wizard_url('requirements')) ?>" class="stack" data-once>
  <?= csrf_field() ?>
  <?php if (!$failed && !$https): ?>
    <div class="ack-box" role="group" aria-labelledby="ack-title">
      <strong id="ack-title">HTTP connection: not secure</strong>
      <span>This installer is open over <strong>http://</strong>, so everything you type, including passwords, travels unencrypted. You can continue <strong>only as a test installation</strong>. MotoSupply will show a warning on every page until HTTPS is active. Before real use, install your host's free SSL certificate, open the site with <strong>https://</strong> and turn on <em>Require HTTPS</em> in Settings → System Check.</span>
      <label><input type="checkbox" name="testing_ack" value="1"> I understand. This is a test installation, and I will not use real passwords or business data until HTTPS is active.</label>
    </div>
  <?php endif; ?>
  <div class="wizard-actions">
    <a class="btn" href="<?= e(wizard_url('welcome')) ?>">Back</a>
    <a class="btn" href="<?= e(wizard_url('requirements')) ?>">Recheck Requirements</a>
    <?php if (!$failed): ?><button type="submit" class="btn btn-primary">Continue</button><?php endif; ?>
  </div>
</form>
