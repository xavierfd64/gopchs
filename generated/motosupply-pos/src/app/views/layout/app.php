<?php
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Http;
use App\Core\Settings;
use App\Services\Branding;
use App\Services\Theme;

$user = Auth::user();
$nav = $nav ?? '';
$shop = Settings::get('shop_name');
$lowCount = Settings::get('show_low_stock_badge') === '1' && Auth::can('inventory.view') ? low_stock_count() : 0;
$displayName = $user['full_name'] !== '' ? $user['full_name'] : $user['username'];
$firstName = explode(' ', $displayName)[0];
$logo = Branding::logoUrl();
// Sidebar preference (desktop): remembered in a cookie so the page renders without a jump.
$collapsed = ($_COOKIE['moto_sidebar'] ?? '') === 'rail';
$items = [
    ['dashboard', 'Dashboard', 'dashboard', 'dashboard', 'dashboard.view'],
    ['pos', 'POS', 'pos', 'pos', 'pos.access'],
    ['products', 'Inventory', 'products', 'inventory', 'inventory.view'],
    ['sales', 'Sales History', 'sales', 'receipt', 'sales.view'],
    ['reports', 'Reports', 'reports', 'reports', 'reports.view'],
    ['users', 'Users', 'users', 'users', 'users.manage'],
];
$settingsTabs = App\Controllers\SettingsController::tabs();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'MotoSupply') ?> · <?= e($shop) ?></title>
<link rel="icon" href="<?= e(Branding::faviconUrl()) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('theme.css', ['v' => Theme::version()])) ?>">
</head>
<body class="app<?= $nav === 'pos' ? ' is-pos' : '' ?><?= $collapsed ? ' sidebar-rail' : '' ?>" data-tz="<?= e(Settings::get('timezone')) ?>">
<?php include MOTO_ROOT . '/app/views/partials/icons.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="brand">
    <?php if ($logo): ?>
      <img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e($shop) ?>">
    <?php else: ?>
      <span class="brand-mark" aria-hidden="true">M</span>
      <span class="brand-text"><strong>MOTO<span>SUPPLY</span></strong><small>RETAIL SYSTEM</small></span>
    <?php endif; ?>
    <button type="button" class="rail-toggle" data-rail-toggle aria-controls="sidebar" aria-pressed="<?= $collapsed ? 'true' : 'false' ?>" aria-label="<?= $collapsed ? 'Expand sidebar' : 'Collapse sidebar' ?>" title="<?= $collapsed ? 'Expand sidebar' : 'Collapse sidebar' ?>"><?= icon('chevron-left') ?></button>
  </div>
  <nav class="nav">
    <?php foreach ($items as [$key, $label, $route, $ic, $perm]): if (!Auth::can($perm)) { continue; } ?>
      <a href="<?= e(url($route)) ?>" class="nav-link<?= $nav === $key ? ' is-active' : '' ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?> title="<?= e($label) ?>">
        <?= icon($ic) ?><span class="nav-label"><?= e($label) ?></span>
        <?php if ($key === 'products' && $lowCount > 0): ?><span class="nav-badge" title="Low or out of stock"><?= $lowCount ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if ($settingsTabs): ?>
      <a href="<?= e(url((string) array_key_first($settingsTabs))) ?>" class="nav-link<?= $nav === 'settings' ? ' is-active' : '' ?>"<?= $nav === 'settings' ? ' aria-current="page"' : '' ?> title="Settings"><?= icon('settings') ?><span class="nav-label">Settings</span></a>
    <?php endif; ?>
    <form method="post" action="<?= e(url('logout')) ?>" class="nav-form">
      <?= csrf_field() ?>
      <button type="submit" class="nav-link" title="Logout"><?= icon('logout') ?><span class="nav-label">Logout</span></button>
    </form>
  </nav>
  <div class="store-card">
    <small>STORE</small>
    <strong><?= e($shop) ?></strong>
    <?php if (Settings::get('shop_address') !== ''): ?><span><?= e(Settings::get('shop_address')) ?></span><?php endif; ?>
  </div>
  <a class="user-card" href="<?= e(url('account')) ?>" title="My account">
    <span class="avatar"><?= e(person_initials($displayName)) ?></span>
    <span class="user-meta"><strong><?= e($displayName) ?></strong><small><?= e($user['role_name'] ?? 'User') ?></small></span>
  </a>
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
      <?php if ($nav !== 'pos' && Auth::can('inventory.view')): ?>
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
      <?php if (Auth::can('inventory.view')): ?>
      <a class="icon-btn" href="<?= e(url('products', ['stock' => 'low'])) ?>" aria-label="Low-stock products<?= $lowCount ? " ($lowCount)" : '' ?>" title="Low-stock products">
        <?= icon('bell') ?><?php if ($lowCount > 0): ?><span class="dot" aria-hidden="true"></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <a class="avatar avatar-sm" href="<?= e(url('account')) ?>" title="My account" aria-label="My account"><?= e(person_initials($displayName)) ?></a>
    </div>
  </header>
  <?php include MOTO_ROOT . '/app/views/partials/insecure_banner.php'; ?>
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
