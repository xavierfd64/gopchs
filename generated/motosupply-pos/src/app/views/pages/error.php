<section class="<?= \App\Core\Auth::check() ? 'page' : 'auth-card' ?>">
  <div class="empty-state">
    <?= icon('alert', 'icon-lg') ?>
    <h2><?= e($title) ?></h2>
    <p><?= e($message) ?></p>
    <a class="btn" href="<?= e(url('dashboard')) ?>">Go to dashboard</a>
  </div>
</section>
