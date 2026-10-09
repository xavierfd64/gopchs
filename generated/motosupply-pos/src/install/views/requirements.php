<?php defined('MOTO_ROOT') || exit;
use App\Services\Requirements;
$failed = Requirements::hasFailures($checks);
$labels = ['pass' => ['Passed', 'pill-success'], 'warn' => ['Warning', 'pill-warning'], 'fail' => ['Failed', 'pill-danger']];
?>
<h1>System requirements</h1>
<p class="lead">The installer checked this server. <?= $failed ? 'Some items failed and must be fixed before continuing.' : 'Everything required is in place.' ?></p>
<div class="table-wrap">
  <table class="table req-table">
    <thead><tr><th scope="col">Requirement</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
    <tbody>
    <?php foreach ($checks as [$label, $status, $detail, $fix]): ?>
      <tr>
        <td><?= e($label) ?></td>
        <td><span class="pill <?= $labels[$status][1] ?>"><?= $labels[$status][0] ?></span></td>
        <td><?= e($detail) ?><?php if ($fix !== ''): ?><small class="fix"><?= e($fix) ?></small><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<form method="post" action="<?= e(wizard_url('requirements')) ?>" class="wizard-actions" data-once>
  <?= csrf_field() ?>
  <a class="btn" href="<?= e(wizard_url('welcome')) ?>">Back</a>
  <?php if ($failed): ?>
    <a class="btn" href="<?= e(wizard_url('requirements')) ?>">Check again</a>
  <?php else: ?>
    <button type="submit" class="btn btn-primary">Continue</button>
  <?php endif; ?>
</form>
