<?php
use App\Services\Branding;
use App\Services\Theme;
$logo = Branding::logoUrl();
$fav = \App\Core\Settings::get('favicon_path');
?>
<div class="page">
  <div class="page-head"><div><h2 class="page-title">Settings</h2><p class="muted">Colours, logo and browser icon.</p></div></div>
  <?php include __DIR__ . '/../partials/settings_tabs.php'; ?>
  <form class="card card-pad stack" method="post" action="<?= e(url('settings.appearance')) ?>" data-once>
    <?= csrf_field() ?>
    <h3>Theme colours</h3>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <p class="small muted">Changes preview live on this page. Text colours adjust automatically for readability; colours for errors, warnings and success stay fixed.</p>
    <div class="form-grid">
      <div class="field"><label for="theme_primary">Primary colour (buttons, links, active items)</label>
        <div class="color-row"><input type="color" id="theme_primary" name="theme_primary" value="<?= e($s['theme_primary']) ?>" data-theme-var="--accent"><code data-color-code="theme_primary"><?= e($s['theme_primary']) ?></code></div></div>
      <div class="field"><label for="theme_sidebar">Sidebar colour</label>
        <div class="color-row"><input type="color" id="theme_sidebar" name="theme_sidebar" value="<?= e($s['theme_sidebar']) ?>" data-theme-var="--sidebar"><code data-color-code="theme_sidebar"><?= e($s['theme_sidebar']) ?></code></div></div>
    </div>
    <div class="theme-preview" aria-label="Preview">
      <div class="tp-sidebar"><span class="tp-nav is-active">Dashboard</span><span class="tp-nav">POS</span><span class="tp-nav">Inventory</span></div>
      <div class="tp-body"><button type="button" class="btn btn-primary" tabindex="-1">Primary button</button> <a class="link-accent" href="#" tabindex="-1">A link</a> <span class="pill pill-success">Paid</span> <span class="pill pill-danger">Voided</span>
        <p class="small muted" data-contrast-msg></p></div>
    </div>
    <div class="form-actions">
      <button type="submit" name="reset" value="1" class="btn btn-ghost">Reset to default</button>
      <button type="submit" class="btn btn-primary">Save theme</button>
    </div>
  </form>

  <section class="card card-pad stack" id="branding">
    <h3>Business logo and browser icon</h3>
    <?php if ($brandError): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e($brandError) ?></span></div><?php endif; ?>
    <div class="brand-grid">
      <div class="stack">
        <strong>Logo</strong>
        <div class="logo-preview"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Current logo"><?php else: ?><span class="brand-mark" aria-hidden="true">M</span><span class="muted small">Default mark</span><?php endif; ?></div>
        <p class="small muted">PNG, JPG or WEBP, up to 1 MB. Shown in the sidebar, on the login page and on receipts. A wide logo with a transparent background works best.</p>
        <form method="post" action="<?= e(url('settings.branding')) ?>" enctype="multipart/form-data" class="inline-form" data-once>
          <?= csrf_field() ?><input type="hidden" name="kind" value="logo">
          <label class="visually-hidden" for="logo">Logo file</label><input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
          <button type="submit" class="btn btn-sm"><?= icon('upload') ?> <?= $logo ? 'Replace' : 'Upload' ?></button>
        </form>
        <?php if ($logo): ?><form method="post" action="<?= e(url('settings.branding')) ?>" data-confirm="Remove the logo and use the default mark?"><?= csrf_field() ?><input type="hidden" name="kind" value="logo"><input type="hidden" name="remove" value="1"><button class="btn btn-sm btn-ghost" type="submit">Remove logo</button></form><?php endif; ?>
      </div>
      <div class="stack">
        <strong>Browser icon (favicon)</strong>
        <div class="logo-preview"><img src="<?= e(Branding::faviconUrl()) ?>" alt="Current browser icon" class="fav-preview"><span class="muted small"><?= $fav !== '' ? 'Custom icon' : 'Default icon' ?></span></div>
        <p class="small muted">PNG or ICO, square, up to 256 KB (for example 64×64 or 180×180 pixels).</p>
        <form method="post" action="<?= e(url('settings.branding')) ?>" enctype="multipart/form-data" class="inline-form" data-once>
          <?= csrf_field() ?><input type="hidden" name="kind" value="favicon">
          <label class="visually-hidden" for="favicon">Icon file</label><input id="favicon" type="file" name="favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico" required>
          <button type="submit" class="btn btn-sm"><?= icon('upload') ?> <?= $fav !== '' ? 'Replace' : 'Upload' ?></button>
        </form>
        <?php if ($fav !== ''): ?><form method="post" action="<?= e(url('settings.branding')) ?>" data-confirm="Remove the custom browser icon?"><?= csrf_field() ?><input type="hidden" name="kind" value="favicon"><input type="hidden" name="remove" value="1"><button class="btn btn-sm btn-ghost" type="submit">Remove icon</button></form><?php endif; ?>
      </div>
    </div>
  </section>
</div>
