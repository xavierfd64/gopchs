<?php
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Http;
use App\Core\Settings;

$user = Auth::user();
$nav = $nav ?? '';
$shop = Settings::get('shop_name');
$lowCount = Settings::get('show_low_stock_badge') === '1' ? low_stock_count() : 0;
$displayName = $user['full_name'] !== '' ? $user['full_name'] : $user['username'];
$firstName = explode(' ', $displayName)[0];
$items = [
    ['dashboard', 'Dashboard', 'dashboard', 'dashboard'],
    ['pos', 'POS', 'pos', 'pos'],
    ['products', 'Inventory', 'products', 'inventory'],
    ['sales', 'Sales History', 'sales', 'receipt'],
    ['reports', 'Reports', 'reports', 'reports'],
    ['settings', 'Settings', 'settings', 'settings'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'MotoSupply') ?> · <?= e($shop) ?></title>
<link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app<?= $nav === 'pos' ? ' is-pos' : '' ?>" data-tz="<?= e(Settings::get('timezone')) ?>">
<?php include MOTO_ROOT . '/app/views/partials/icons.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="brand">
    <span class="brand-mark" aria-hidden="true">M</span>
    <span class="brand-text"><strong>MOTO<span>SUPPLY</span></strong><small>RETAIL SYSTEM</small></span>
  </div>
  <nav class="nav">
    <?php foreach ($items as [$key, $label, $route, $ic]): ?>
      <a href="<?= e(url($route)) ?>" class="nav-link<?= $nav === $key ? ' is-active' : '' ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>>
        <?= icon($ic) ?><span><?= e($label) ?></span>
        <?php if ($key === 'products' && $lowCount > 0): ?><span class="nav-badge" title="Low or out of stock"><?= $lowCount ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('logout')) ?>" class="nav-form">
      <?= csrf_field() ?>
      <button type="submit" class="nav-link"><?= icon('logout') ?><span>Logout</span></button>
    </form>
  </nav>
  <div class="store-card">
    <small>STORE</small>
    <strong><?= e($shop) ?></strong>
    <?php if (Settings::get('shop_address') !== ''): ?><span><?= e(Settings::get('shop_address')) ?></span><?php endif; ?>
  </div>
  <div class="user-card">
    <span class="avatar"><?= e(person_initials($displayName)) ?></span>
    <span class="user-meta"><strong><?= e($displayName) ?></strong><small>Administrator</small></span>
  </div>
</aside>
<div class="sidebar-backdrop" data-close-sidebar hidden></div>
<div class="main-wrap">
  <header class="topbar">
    <button type="button" class="icon-btn menu-btn" data-open-sidebar aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><?= icon('menu') ?></button>
    <div class="topbar-title">
      <h1><?= e($heading ?? $title ?? '') ?></h1>
      <p><?= e($subtitle ?? ('Welcome back, ' . $firstName)) ?></p>
    </div>
    <div class="topbar-actions">
      <?php if ($nav !== 'pos'): ?>
      <form class="top-search" method="get" action="<?= e(Http::basePath() . '/index.php') ?>" role="search">
        <input type="hidden" name="r" value="products">
        <label class="visually-hidden" for="top-search">Search products</label>
        <?= icon('search') ?>
        <input id="top-search" type="search" name="q" placeholder="Search products…" autocomplete="off">
      </form>
      <?php endif; ?>
      <div class="clock" aria-label="Shop time">
        <?= icon('clock') ?>
        <span><strong data-clock-time><?= e(Clock::nowLocal()->format('g:i A')) ?></strong><small data-clock-date><?= e(strtoupper(Clock::nowLocal()->format('M j, Y'))) ?></small></span>
      </div>
      <a class="icon-btn" href="<?= e(url('products', ['stock' => 'low'])) ?>" aria-label="Low-stock products<?= $lowCount ? " ($lowCount)" : '' ?>" title="Low-stock products">
        <?= icon('bell') ?><?php if ($lowCount > 0): ?><span class="dot" aria-hidden="true"></span><?php endif; ?>
      </a>
      <a class="avatar avatar-sm" href="<?= e(url('settings')) ?>" title="Account settings" aria-label="Account settings"><?= e(person_initials($displayName)) ?></a>
    </div>
  </header>
  <main id="main" class="main" tabindex="-1">
    <?php foreach (Http::takeFlash() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= icon($f['type'] === 'error' ? 'alert' : 'check') ?><span><?= e($f['message']) ?></span></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts ?? [] as $s): ?><script src="<?= e(asset($s)) ?>"></script><?php endforeach; ?>
</body>
</html>
