<?php
use App\Core\Settings;
use App\Services\Branding;
use App\Services\Theme;
$logo = Branding::logoUrl();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Log in') ?> · <?= e(Settings::get('shop_name')) ?></title>
<link rel="icon" href="<?= e(Branding::faviconUrl()) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css', ['v' => Theme::version()])) ?>">
</head>
<body class="guest<?= !empty($loginPage) ? ' is-login' : '' ?>">
<?php include MOTO_ROOT . '/app/views/partials/icons.php'; ?>
<main class="guest-main" id="main">
  <?php include MOTO_ROOT . '/app/views/partials/insecure_banner.php'; ?>
  <?php if (empty($loginPage)): ?>
  <div class="guest-brand">
    <?php if ($logo): ?><img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e(Settings::get('shop_name')) ?>"><?php else: ?>
    <span class="brand-mark" aria-hidden="true">M</span>
    <span class="brand-text"><strong>MOTO<span>SUPPLY</span></strong><small>RETAIL SYSTEM</small></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
