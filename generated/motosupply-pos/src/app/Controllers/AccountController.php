<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Http;
use App\Services\VoidAuthorization;

/** The signed-in user's own account: password and (for approvers) the void PIN. */
final class AccountController extends Controller
{
    public function index(): void
    {
        $this->view('pages/account', [
            'title' => 'My account',
            'nav' => 'account',
            'pwError' => $_SESSION['_pw_error'] ?? null,
            'pinError' => $_SESSION['_pin_error'] ?? null,
        ]);
        unset($_SESSION['_pw_error'], $_SESSION['_pin_error']);
    }

    public function password(): void
    {
        $user = Auth::user();
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $error = !Auth::verifyCurrentPassword($current) ? 'Your current password is incorrect.'
            : (hash_equals($current, $new) ? 'The new password must be different from the current password.'
            : Auth::validateNewPassword($new, $confirm, (string) $user['username']));
        if ($error !== null) {
            Audit::log('user.password.change', 'user', (int) $user['id'], ['error' => $error], 'failure');
            $_SESSION['_pw_error'] = $error;
            Http::redirect(url('account') . '#password');
        }
        Auth::changePassword((int) $user['id'], $new);
        Audit::log('user.password.change', 'user', (int) $user['id']);
        Http::flash('success', 'Your password has been changed.');
        Http::redirect(url('account'));
    }

    /** Set or change the separate void-approval PIN. Requires the login password to confirm identity. */
    public function pin(): void
    {
        $user = Auth::user();
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $pin = is_string($_POST['pin'] ?? null) ? $_POST['pin'] : '';
        $pin2 = is_string($_POST['pin2'] ?? null) ? $_POST['pin2'] : '';
        $error = !Auth::verifyCurrentPassword($current) ? 'Your current password is incorrect.'
            : (VoidAuthorization::pinPolicyError($pin) ?? (hash_equals($pin, $pin2) ? null : 'The PINs do not match.'));
        if ($error === null && password_verify($pin, (string) \App\Core\DB::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]))) {
            $error = 'The void PIN must be different from your login password.';
        }
        if ($error !== null) {
            Audit::log('user.void_pin.set', 'user', (int) $user['id'], ['error' => $error], 'failure');
            $_SESSION['_pin_error'] = $error;
            Http::redirect(url('account') . '#void-pin');
        }
        VoidAuthorization::setPin((int) $user['id'], $pin);
        Audit::log('user.void_pin.set', 'user', (int) $user['id']);
        Http::flash('success', 'Your void approval PIN is saved. It is stored only as a secure hash and is never shown again.');
        Http::redirect(url('account'));
    }
}
