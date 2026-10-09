<?php
use App\Core\Permissions;
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$rolePerms = [];
foreach ($roles as $r) {
    $rolePerms[(int) $r['id']] = $r['permissions'];
}
$selRole = (int) ($v['role_id'] ?? 0);
?>
<div class="page page-narrow">
  <div class="page-head"><div>
    <a class="back-link" href="<?= e(url('users')) ?>">← Users</a>
    <h2 class="page-title"><?= $user ? 'Edit ' . e($user['username']) : 'Add user' ?></h2>
    <?php if ($self): ?><p class="muted">You cannot change your own role or permissions. Ask another administrator.</p><?php endif; ?>
  </div></div>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>
  <form class="card card-pad stack" method="post" action="<?= e(url('users.save')) ?>" data-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($user['id'] ?? 0) ?>">
    <div class="form-grid">
      <div class="field"><label for="username">Username</label><input id="username" name="username" required maxlength="50" autocomplete="off" value="<?= e($v['username'] ?? '') ?>"<?= $inv('username') ?>><?= $err('username') ?></div>
      <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="100" value="<?= e($v['full_name'] ?? '') ?>"<?= $inv('full_name') ?>><?= $err('full_name') ?></div>
      <?php if (!$user): ?>
      <div class="field"><label for="password">Initial password</label><input id="password" name="password" type="password" autocomplete="new-password" required maxlength="72"<?= $inv('password') ?>><?= $err('password') ?><small class="hint">The user must change it at first sign-in.</small></div>
      <div class="field"><label for="password2">Confirm password</label><input id="password2" name="password2" type="password" autocomplete="new-password" required maxlength="72"></div>
      <?php endif; ?>
      <div class="field span-2"><label for="role_id">Role</label>
        <select id="role_id" name="role_id"<?= $self ? ' disabled' : '' ?> data-role-select<?= $inv('role_id') ?>>
          <option value="">Choose a role…</option>
          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $selRole === (int) $r['id'] ? ' selected' : '' ?> data-perms="<?= e(implode(',', $r['permissions'])) ?>"><?= e($r['name']) ?> — <?= e($r['description']) ?></option><?php endforeach; ?>
        </select><?= $err('role_id') ?></div>
    </div>
    <fieldset class="field"<?= $self ? ' disabled' : '' ?>>
      <legend>Permissions</legend>
      <p class="small muted">"Role" uses the role's setting (✓ = included). Choose Allow or Deny to customise this user only. Administrators always have every permission.</p>
      <?= $err('overrides') ?>
      <div class="perm-table">
        <?php foreach (Permissions::CATALOG as $group => $perms): ?>
          <div class="perm-group"><strong><?= e($group) ?></strong>
          <?php foreach ($perms as $perm => $label): $mode = $v['overrides'][$perm] ?? ''; $id = 'perm-' . str_replace('.', '-', $perm); ?>
            <div class="perm-row">
              <label for="<?= $id ?>"><?= e($label) ?> <span class="role-has" data-role-has="<?= e($perm) ?>" aria-hidden="true"></span></label>
              <select id="<?= $id ?>" name="perm[<?= e($perm) ?>]" class="input-sm">
                <option value=""<?= $mode === '' ? ' selected' : '' ?>>Role</option>
                <option value="allow"<?= $mode === 'allow' ? ' selected' : '' ?>>Allow</option>
                <option value="deny"<?= $mode === 'deny' ? ' selected' : '' ?>>Deny</option>
              </select>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="form-actions"><a class="btn" href="<?= e(url('users')) ?>">Cancel</a><button type="submit" class="btn btn-primary"><?= $user ? 'Save changes' : 'Create user' ?></button></div>
  </form>
</div>
