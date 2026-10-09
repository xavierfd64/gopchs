<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Http;
use App\Core\ValidationException;
use App\Services\UserService;
use App\Services\VoidAuthorization;

final class UserController extends Controller
{
    public function index(): void
    {
        $this->view('pages/users', [
            'title' => 'Users & permissions',
            'nav' => 'users',
            'users' => UserService::list(),
            'roles' => UserService::roles(),
            'tempPassword' => $_SESSION['_temp_password'] ?? null,
        ]);
        unset($_SESSION['_temp_password']);
    }

    public function form(): void
    {
        [$old, $errors] = $this->takeOld();
        $id = $this->idParam();
        $user = $id > 0 ? UserService::find($id) : null;
        if ($id > 0 && $user === null) {
            $this->notFound('user');
        }
        $this->view('pages/user_form', [
            'title' => $user ? 'Edit user' : 'Add user',
            'nav' => 'users',
            'user' => $user,
            'roles' => UserService::roles(),
            'v' => $old ?: ($user ? ['username' => $user['username'], 'full_name' => $user['full_name'], 'role_id' => $user['role_id'], 'overrides' => $user['overrides']] : ['overrides' => []]),
            'errors' => $errors,
            'self' => $user !== null && (int) $user['id'] === Auth::id(),
        ]);
    }

    public function save(): void
    {
        $id = $this->idParam('id', 'post');
        $in = [
            'username' => Http::post('username'),
            'full_name' => Http::post('full_name'),
            'role_id' => Http::post('role_id'),
            'password' => is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
            'password2' => is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '',
            'overrides' => is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [],
        ];
        $before = $id > 0 ? UserService::find($id) : null;
        try {
            $newId = UserService::save($id, $in, Auth::user());
            $after = UserService::find($newId);
            Audit::log($id > 0 ? 'user.update' : 'user.create', 'user', $newId, [
                'username' => $after['username'],
                'role' => ['from' => $before['role_slug'] ?? null, 'to' => $after['role_slug']],
                'overrides' => ['from' => $before['overrides'] ?? [], 'to' => $after['overrides']],
            ]);
            Http::flash('success', $id > 0 ? 'User updated.' : 'User created. They must change the password at first login.');
            Http::redirect(url('users'));
        } catch (ValidationException $e) {
            Audit::log($id > 0 ? 'user.update' : 'user.create', 'user', $id ?: $in['username'], ['error' => $e->getMessage()], 'failure');
            unset($in['password'], $in['password2']);
            $this->withOld($in, $e->errors);
            Http::redirect($id > 0 ? url('users.edit', ['id' => $id]) : url('users.create'));
        }
    }

    public function status(): void
    {
        $id = $this->idParam('id', 'post');
        $active = Http::post('active') === '1';
        try {
            UserService::setActive($id, $active, Auth::user());
            Audit::log($active ? 'user.activate' : 'user.deactivate', 'user', $id);
            Http::flash('success', $active ? 'User activated.' : 'User deactivated. They are signed out on their next request.');
        } catch (ValidationException $e) {
            Audit::log($active ? 'user.activate' : 'user.deactivate', 'user', $id, ['error' => $e->getMessage()], 'failure');
            Http::flash('error', $e->getMessage());
        }
        Http::redirect(url('users'));
    }

    public function resetPassword(): void
    {
        $id = $this->idParam('id', 'post');
        try {
            $temp = UserService::resetPassword($id, Auth::user());
            Audit::log('user.password.reset', 'user', $id);
            $u = UserService::find($id);
            $_SESSION['_temp_password'] = ['username' => $u['username'], 'password' => $temp];
        } catch (ValidationException $e) {
            Audit::log('user.password.reset', 'user', $id, ['error' => $e->getMessage()], 'failure');
            Http::flash('error', $e->getMessage());
        }
        Http::redirect(url('users'));
    }

    public function clearPin(): void
    {
        $id = $this->idParam('id', 'post');
        $u = UserService::find($id);
        if ($u === null || ($u['role_slug'] === 'administrator' && !Auth::isAdmin())) {
            Http::flash('error', 'Not allowed.');
        } else {
            VoidAuthorization::clearPin($id);
            Audit::log('user.void_pin.clear', 'user', $id);
            Http::flash('success', 'The void PIN of ' . $u['username'] . ' was removed. They can set a new one in My Account.');
        }
        Http::redirect(url('users'));
    }
}
