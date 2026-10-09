<?php defined('MOTO_ROOT') || exit;
$keys = array_keys(STEPS);
$current = array_search($step, $keys, true);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(STEPS[$step] ?? 'Install') ?> · Install MotoSupply POS</title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest wizard">
<main class="wizard-main" id="main">
  <div class="guest-brand"><span class="brand-mark" aria-hidden="true">M</span><span class="brand-text"><strong>MOTO<span>SUPPLY</span></strong><small>INSTALLATION</small></span></div>
  <div class="wizard-card">
    <ol class="stepper" aria-label="Installation steps">
      <?php foreach (STEPS as $k => $label): $i = array_search($k, $keys, true); ?>
        <li class="<?= $i < $current ? 'is-done' : ($i === $current ? 'is-current' : '') ?>"<?= $i === $current ? ' aria-current="step"' : '' ?>>
          <span class="step-dot"><?= $i < $current ? '✓' : $i + 1 ?></span><span class="step-label"><?= e($label) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <section class="wizard-body">
      <?php if (!empty($errors['_form'])): ?><div class="alert alert-error" role="alert"><span><?= e($errors['_form']) ?></span></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="alert alert-success" role="status"><span><?= e($notice) ?></span></div><?php endif; ?>
      <?= $content ?>
    </section>
  </div>
  <p class="wizard-foot">MotoSupply POS &amp; Inventory System <?= e(MOTO_VERSION) ?></p>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
