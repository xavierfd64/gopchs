<?php $current = \App\Core\Http::query('r', 'settings'); ?>
<nav class="settings-tabs" aria-label="Settings sections">
  <?php foreach (\App\Controllers\SettingsController::tabs() as $route => [$label, , $ic]): ?>
    <a href="<?= e(url($route)) ?>" class="<?= $current === $route ? 'is-active' : '' ?>"<?= $current === $route ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</nav>
