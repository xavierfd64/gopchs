<?php
$labels = ['ok' => ['OK', 'pill-success'], 'warn' => ['Warning', 'pill-warning'], 'fail' => ['Failed', 'pill-danger']];
?>
<div class="table-wrap">
  <table class="table req-table">
    <thead><tr><th scope="col">Requirement</th><th scope="col">Status</th><th scope="col">Detected result and what to do</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><span class="pill <?= $labels[$c['status']][1] ?>"><?= $labels[$c['status']][0] ?></span></td>
        <td><?= e($c['result']) ?>
          <?php if ($c['explain'] !== ''): ?><small class="req-explain"><?= e($c['explain']) ?></small><?php endif; ?>
          <?php if ($c['action'] !== ''): ?><small class="req-action"><strong>What to do:</strong> <?= e($c['action']) ?></small><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
