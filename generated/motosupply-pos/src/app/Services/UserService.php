<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Permissions;
use App\Core\ValidationException;

/**
 * User accounts and permission assignment, with these safety rules (all enforced here):
 *  - nobody can change their own role, permission overrides or active status;
 *  - only administrators can create, edit or assign the Administrator role;
 *  - a non-administrator can only grant permissions they hold themselves;
 *  - the last active administrator cannot be deactivated or demoted.
 */
final class UserService
{
    public static function roles(): array
    {
        $roles = DB::all('SELECT * FROM roles ORDER BY FIELD(slug, \'administrator\', \'cashier\', \'inventory\', \'reports\', \'custom\'), name');
        foreach ($roles as &$r) {
            $r['permissions'] = $r['slug'] === 'administrator'
                ? Permissions::all()
                : array_column(DB::all('SELECT permission FROM role_permissions WHERE role_id = ?', [$r['id']]), 'permission');
        }
        return $roles;
    }

    public static function list(): array
    {
        return DB::all(
            'SELECT u.id, u.username, u.full_name, u.is_active, u.last_login_at, u.void_pin_hash IS NOT NULL AS has_pin,
                    r.name AS role_name, r.slug AS role_slug,
                    (SELECT COUNT(*) FROM user_permissions up WHERE up.user_id = u.id) AS overrides
               FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.is_active DESC, u.username'
        );
    }

    public static function find(int $id): ?array
    {
        $u = DB::one('SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
        if ($u === null) {
            return null;
        }
        $u['overrides'] = [];
        foreach (DB::all('SELECT permission, allowed FROM user_permissions WHERE user_id = ?', [$id]) as $o) {
            $u['overrides'][$o['permission']] = (int) $o['allowed'] === 1 ? 'allow' : 'deny';
        }
        return $u;
    }

    private static function activeAdminCount(): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'administrator' AND u.is_active = 1");
    }

    private static function role(int $roleId): ?array
    {
        foreach (self::roles() as $r) {
            if ((int) $r['id'] === $roleId) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Create (id = 0) or update a user. Returns the user id.
     * @param array{username?:string,full_name?:string,role_id?:string,password?:string,password2?:string,overrides?:array} $in
     */
    public static function save(int $id, array $in, array $actor): int
    {
        $errors = [];
        $actorIsAdmin = ($actor['role_slug'] ?? '') === 'administrator';
        $existing = $id > 0 ? self::find($id) : null;
        if ($id > 0 && $existing === null) {
            throw new ValidationException(['user' => 'User not found.']);
        }
        $self = $existing !== null && (int) $existing['id'] === (int) $actor['id'];
        if ($existing !== null && $existing['role_slug'] === 'administrator' && !$actorIsAdmin) {
            throw new ValidationException(['user' => 'Only an administrator can edit an administrator account.']);
        }

        $username = trim((string) ($in['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._\-]{3,50}$/', $username)) {
            $errors['username'] = 'Username: 3 to 50 letters, numbers, dots, dashes or underscores.';
        } elseif (DB::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]) !== null) {
            $errors['username'] = 'That username is already taken.';
        }
        $fullName = trim((string) ($in['full_name'] ?? ''));
        if (mb_strlen($fullName) > 100) {
            $errors['full_name'] = 'Name is limited to 100 characters.';
        }

        $roleId = (int) ($in['role_id'] ?? 0);
        $role = self::role($roleId);
        $overrides = [];
        if ($self) {
            // Own role and permissions are never changed through this form.
            $roleId = (int) $existing['role_id'];
            $role = self::role($roleId);
            $overrides = $existing['overrides'];
        } else {
            if ($role === null) {
                $errors['role_id'] = 'Choose a role.';
            } elseif ($role['slug'] === 'administrator' && !$actorIsAdmin) {
                $errors['role_id'] = 'Only an administrator can assign the Administrator role.';
            } elseif (!$actorIsAdmin && array_diff($role['permissions'], $actor['permissions']) !== []) {
                $errors['role_id'] = 'You can only assign roles whose permissions you hold yourself.';
            }
            foreach ((array) ($in['overrides'] ?? []) as $perm => $mode) {
                if (!is_string($perm) || !Permissions::exists($perm) || !in_array($mode, ['allow', 'deny'], true)) {
                    continue;
                }
                if ($mode === 'allow' && !$actorIsAdmin && !in_array($perm, $actor['permissions'], true)) {
                    $errors['overrides'] = 'You can only grant permissions you hold yourself (' . Permissions::label($perm) . ').';
                    continue;
                }
                $overrides[$perm] = $mode;
            }
            if ($existing !== null && $existing['role_slug'] === 'administrator' && (int) $existing['is_active'] === 1
                && ($role['slug'] ?? '') !== 'administrator' && self::activeAdminCount() <= 1) {
                $errors['role_id'] = 'This is the last active administrator. Make another user an administrator first.';
            }
        }

        $password = (string) ($in['password'] ?? '');
        if ($existing === null) {
            $err = \App\Core\Auth::validateNewPassword($password, (string) ($in['password2'] ?? ''), $username);
            if ($err !== null) {
                $errors['password'] = $err;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        return DB::transaction(static function () use ($existing, $username, $fullName, $roleId, $role, $overrides, $password): int {
            $now = Clock::nowUtc();
            if ($existing === null) {
                $id = DB::insert(
                    'INSERT INTO users (username, password_hash, full_name, role, role_id, must_change_password, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)',
                    [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role['slug'], $roleId, $now, $now]
                );
            } else {
                $id = (int) $existing['id'];
                DB::run('UPDATE users SET username = ?, full_name = ?, role = ?, role_id = ?, updated_at = ? WHERE id = ?',
                    [$username, $fullName, $role['slug'], $roleId, $now, $id]);
            }
            DB::run('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
            foreach ($overrides as $perm => $mode) {
                DB::run('INSERT INTO user_permissions (user_id, permission, allowed) VALUES (?, ?, ?)', [$id, $perm, $mode === 'allow' ? 1 : 0]);
            }
            return $id;
        });
    }

    public static function setActive(int $id, bool $active, array $actor): void
    {
        $u = self::find($id);
        if ($u === null) {
            throw new ValidationException(['user' => 'User not found.']);
        }
        if ((int) $u['id'] === (int) $actor['id']) {
            throw new ValidationException(['user' => 'You cannot deactivate your own account.']);
        }
        if ($u['role_slug'] === 'administrator' && ($actor['role_slug'] ?? '') !== 'administrator') {
            throw new ValidationException(['user' => 'Only an administrator can change an administrator account.']);
        }
        if (!$active && $u['role_slug'] === 'administrator' && (int) $u['is_active'] === 1 && self::activeAdminCount() <= 1) {
            throw new ValidationException(['user' => 'This is the last active administrator and cannot be deactivated.']);
        }
        DB::run('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, Clock::nowUtc(), $id]);
        if (!$active) {
            ApiTokens::revokeAllForUser($id); // signs the user out of the cashier desktop app too
        }
    }

    /** Set a temporary password the user must change at next login. Returns it (shown once). */
    public static function resetPassword(int $id, array $actor): string
    {
        $u = self::find($id);
        if ($u === null) {
            throw new ValidationException(['user' => 'User not found.']);
        }
        if ($u['role_slug'] === 'administrator' && ($actor['role_slug'] ?? '') !== 'administrator') {
            throw new ValidationException(['user' => 'Only an administrator can reset an administrator password.']);
        }
        if ((int) $u['id'] === (int) $actor['id']) {
            throw new ValidationException(['user' => 'Use My Account to change your own password.']);
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 10; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $temp .= '#' . random_int(10, 99);
        DB::run('UPDATE users SET password_hash = ?, must_change_password = 1, updated_at = ? WHERE id = ?',
            [password_hash($temp, PASSWORD_DEFAULT), Clock::nowUtc(), $id]);
        ApiTokens::revokeAllForUser($id);
        return $temp;
    }
}
