<?php use App\Core\Settings; ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Log in') ?> · <?= e(Settings::get('shop_name')) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest">
<?php include MOTO_ROOT . '/app/views/partials/icons.php'; ?>
<main class="guest-main" id="main">
  <?php include MOTO_ROOT . '/app/views/partials/insecure_banner.php'; ?>
  <div class="guest-brand">
    <span class="brand-mark" aria-hidden="true">M</span>
    <span class="brand-text"><strong>MOTO<span>SUPPLY</span></strong><small>RETAIL SYSTEM</small></span>
  </div>
  <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
