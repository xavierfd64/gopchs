<?php
declare(strict_types=1);

namespace App\Core;

/** Synchronizer-token CSRF protection for every state-changing request. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function valid(?string $token): bool
    {
        return is_string($token) && $token !== '' && isset($_SESSION['_csrf'])
            && hash_equals((string) $_SESSION['_csrf'], $token);
    }

    /** Accepts the token from a form field or the X-CSRF-Token header. */
    public static function check(): bool
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return self::valid(is_string($token) ? $token : null);
    }

    public static function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
}
