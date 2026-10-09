<div class="page">
  <div class="page-head">
    <div><h2 class="page-title">Settings</h2><p class="muted">User accounts, roles and permissions.</p></div>
    <a class="btn btn-primary" href="<?= e(url('users.create')) ?>"><?= icon('plus') ?> Add user</a>
  </div>
  <?php include __DIR__ . '/../partials/settings_tabs.php'; ?>
  <?php if ($tempPassword): ?>
    <div class="alert alert-info" role="status"><?= icon('lock') ?><span>Temporary password for <strong><?= e($tempPassword['username']) ?></strong>: <code class="big-code"><?= e($tempPassword['password']) ?></code><br>Give it to the user privately. They must choose a new password when they sign in. It will not be shown again.</span></div>
  <?php endif; ?>
  <section class="card">
    <div class="table-wrap">
      <table class="table table-cards">
        <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Void PIN</th><th scope="col">Last sign-in</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $isSelf = (int) $u['id'] === \App\Core\Auth::id(); ?>
          <tr class="<?= (int) $u['is_active'] === 0 ? 'is-archived' : '' ?>">
            <td data-label="User"><a class="strong" href="<?= e(url('users.edit', ['id' => $u['id']])) ?>"><?= e($u['username']) ?></a><small class="muted block"><?= e($u['full_name']) ?><?= $isSelf ? ' (you)' : '' ?></small></td>
            <td data-label="Role"><?= e($u['role_name'] ?? '—') ?><?= (int) $u['overrides'] > 0 ? ' <span class="pill pill-muted">+' . (int) $u['overrides'] . ' custom</span>' : '' ?></td>
            <td data-label="Status"><?= (int) $u['is_active'] === 1 ? '<span class="pill pill-success">Active</span>' : '<span class="pill pill-muted">Inactive</span>' ?></td>
            <td data-label="Void PIN"><?= (int) $u['has_pin'] === 1 ? 'Set' : '—' ?></td>
            <td data-label="Last sign-in"><?= e(local_time($u['last_login_at'])) ?: '—' ?></td>
            <td class="actions">
              <details class="menu">
                <summary class="icon-btn" aria-label="Actions for <?= e($u['username']) ?>"><?= icon('more') ?></summary>
                <div class="menu-list">
                  <a href="<?= e(url('users.edit', ['id' => $u['id']])) ?>"><?= icon('edit') ?> Edit</a>
                  <?php if (!$isSelf): ?>
                  <form method="post" action="<?= e(url('users.reset-password')) ?>" data-confirm="Reset the password of <?= e($u['username']) ?>? A temporary password will be shown once."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button type="submit"><?= icon('lock') ?> Reset password</button></form>
                  <?php if ((int) $u['has_pin'] === 1): ?>
                  <form method="post" action="<?= e(url('users.clear-pin')) ?>" data-confirm="Remove the void PIN of <?= e($u['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button type="submit"><?= icon('x') ?> Remove void PIN</button></form>
                  <?php endif; ?>
                  <form method="post" action="<?= e(url('users.status')) ?>" data-confirm="<?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Activate' ?> <?= e($u['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="active" value="<?= (int) $u['is_active'] === 1 ? '0' : '1' ?>"><button type="submit"><?= icon((int) $u['is_active'] === 1 ? 'archive' : 'restore') ?> <?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button></form>
                  <?php endif; ?>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="card card-pad stack">
    <h3>Roles</h3>
    <div class="role-grid">
      <?php foreach ($roles as $r): ?>
        <div class="role-card"><strong><?= e($r['name']) ?></strong><p class="small muted"><?= e($r['description']) ?></p>
          <p class="small"><?= $r['slug'] === 'administrator' ? 'All permissions' : (count($r['permissions']) . ' permission(s)') ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
