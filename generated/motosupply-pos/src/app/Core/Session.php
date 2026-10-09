<?php
declare(strict_types=1);

namespace App\Core;

/** Hardened PHP session handling suitable for shared hosting. */
final class Session
{
    public const NAME = 'MOTOSESS';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $idle = (int) Config::get('app.session_idle_seconds', 1800);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max($idle, 1800));

        // Keep session files private to this application when the storage directory is writable.
        $dir = MOTO_ROOT . '/storage/sessions';
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Http::basePath() ?: '') . '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $absolute = (int) Config::get('app.session_absolute_seconds', 43200);
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        $created = (int) ($_SESSION['_created'] ?? $now);
        if (isset($_SESSION['user_id']) && ($now - $last > $idle || $now - $created > $absolute)) {
            self::destroy();
            session_start();
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_created'] ??= $now;
        $_SESSION['_last_activity'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
            session_destroy();
        }
    }
}
