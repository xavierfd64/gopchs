<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Http;

final class AuthController extends Controller
{
    public function login(): void
    {
        if (Auth::check()) {
            Http::redirect(url('dashboard'));
        }
        $error = null;
        $username = '';
        if (Http::isPost()) {
            $username = Http::post('username');
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if ($username === '' || $password === '') {
                $error = 'Enter your username and password.';
            } else {
                $result = Auth::attempt($username, $password, Http::clientIp());
                Audit::log('auth.login', 'user', mb_substr($username, 0, 50), $result === 'ok' ? [] : ['result' => $result], $result === 'ok' ? 'success' : 'failure',
                    $result === 'ok' ? Auth::user() : ['id' => null, 'username' => mb_substr($username, 0, 50)]);
                if ($result === 'ok') {
                    Http::redirect(url((int) Auth::user()['must_change_password'] === 1 ? 'password.change' : 'dashboard'));
                }
                $error = $result === 'locked'
                    ? 'Too many failed attempts. Please wait ' . Auth::LOCKOUT_MINUTES . ' minutes and try again.'
                    : 'Invalid username or password.';
                http_response_code($result === 'locked' ? 429 : 401);
            }
        }
        $expired = !empty($_SESSION['_expired']);
        unset($_SESSION['_expired']);
        $this->view('pages/login', [
            'title' => 'Log in',
            'error' => $error,
            'username' => $username,
            'expired' => $expired,
            'loginPage' => true,
        ], 'layout/guest');
    }

    public function logout(): void
    {
        Audit::log('auth.logout', 'user', Auth::id());
        Auth::logout();
        Http::redirect(url('login'));
    }

    /** Forced first-login password change (also reachable from Settings). */
    public function changePassword(): void
    {
        $user = Auth::user();
        $error = null;
        if (Http::isPost()) {
            $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
            $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
            $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
            if (!Auth::verifyCurrentPassword($current)) {
                $error = 'Your current password is incorrect.';
            } elseif (hash_equals($current, $new)) {
                $error = 'The new password must be different from the current password.';
            } else {
                $error = Auth::validateNewPassword($new, $confirm, (string) $user['username']);
            }
            if ($error === null) {
                Auth::changePassword((int) $user['id'], $new);
                Audit::log('user.password.change', 'user', (int) $user['id'], ['forced' => (int) $user['must_change_password'] === 1]);
                Http::flash('success', 'Your password has been changed.');
                Http::redirect(url('dashboard'));
            }
        }
        $this->view('pages/change_password', [
            'title' => 'Change password',
            'error' => $error,
            'forced' => (int) $user['must_change_password'] === 1,
        ], 'layout/guest');
    }
}
