<?php
declare(strict_types=1);

namespace App\Core;

/** Server-side authentication with login throttling. */
final class Auth
{
    public const MAX_ATTEMPTS = 5;      // failed attempts per username
    public const MAX_IP_ATTEMPTS = 20;  // failed attempts per IP (shops often share one public IP)
    public const LOCKOUT_MINUTES = 15;
    public const MIN_PASSWORD_LENGTH = 8;

    private static ?array $user = null;

    /**
     * Attempt a login. Returns 'ok', 'invalid' or 'locked'.
     * Failure messages shown to users are deliberately generic.
     */
    public static function attempt(string $username, string $password, string $ip): string
    {
        $username = mb_substr(trim($username), 0, 50);
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);

        $userFailures = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= ? AND username = ?',
            [$since, $username]
        );
        $ipFailures = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= ? AND ip_address = ?',
            [$since, $ip]
        );
        if ($userFailures >= self::MAX_ATTEMPTS || $ipFailures >= self::MAX_IP_ATTEMPTS) {
            self::record($username, $ip, false);
            return 'locked';
        }

        $user = DB::one('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);
        // Always run a hash verification to keep timing similar for unknown users.
        $hash = $user['password_hash'] ?? '$2y$10$v349YXR37Ti.W5Xo4MhIfuuiFimNjym3NPI6gnBhWOYss.893Bixu';
        $valid = password_verify($password, $hash) && $user !== null;

        self::record($username, $ip, $valid);
        if (!$valid) {
            return 'invalid';
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        DB::run('UPDATE users SET last_login_at = ? WHERE id = ?', [Clock::nowUtc(), $user['id']]);

        Session::regenerate();
        Csrf::rotate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['_last_activity'] = time();
        self::$user = null;
        return 'ok';
    }

    private static function record(string $username, string $ip, bool $success): void
    {
        DB::run(
            'INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)',
            [$username, $ip, $success ? 1 : 0, Clock::nowUtc()]
        );
        // Opportunistic cleanup (no cron needed on shared hosting).
        if (random_int(1, 50) === 1) {
            DB::run('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 30)]);
        }
    }

    public static function user(): ?array
    {
        if (self::$user === null && isset($_SESSION['user_id'])) {
            self::$user = DB::one(
                'SELECT id, username, full_name, role, must_change_password FROM users WHERE id = ? AND is_active = 1',
                [(int) $_SESSION['user_id']]
            );
            if (self::$user === null) {
                unset($_SESSION['user_id']);
            }
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    public static function verifyCurrentPassword(string $password): bool
    {
        $hash = DB::value('SELECT password_hash FROM users WHERE id = ?', [self::id()]);
        return is_string($hash) && password_verify($password, $hash);
    }

    /** Returns an error message or null on success. */
    public static function validateNewPassword(string $new, string $confirm, string $username): ?string
    {
        return self::passwordStrengthError($new, $username)
            ?? (hash_equals($new, $confirm) ? null : 'The password and confirmation do not match.');
    }

    /**
     * Strong-password rule used by the installer and password changes: at least
     * MIN_PASSWORD_LENGTH characters, at least three of lower/upper/digit/symbol,
     * not based on the username and not a common password.
     */
    public static function passwordStrengthError(string $pw, string $username): ?string
    {
        if (mb_strlen($pw) < self::MIN_PASSWORD_LENGTH) {
            return 'The password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.';
        }
        if (strlen($pw) > 72) {
            return 'The password must be at most 72 bytes long.';
        }
        $classes = (int) preg_match('/[a-z]/', $pw) + (int) preg_match('/[A-Z]/', $pw)
            + (int) preg_match('/\d/', $pw) + (int) preg_match('/[^a-zA-Z\d]/', $pw);
        if ($classes < 3) {
            return 'Use at least three of these: lowercase letters, uppercase letters, numbers, symbols.';
        }
        $lower = strtolower($pw);
        $common = ['password', 'admin', 'motosupply', 'qwerty', '12345678', '123456789', 'letmein', 'welcome', 'iloveyou'];
        foreach ($common as $word) {
            if (str_contains($lower, $word) && strlen($lower) < strlen($word) + 6) {
                return 'This password is too easy to guess. Choose something less common.';
            }
        }
        if ($username !== '' && str_contains($lower, strtolower($username))) {
            return 'The password must not contain the username.';
        }
        return null;
    }

    public static function changePassword(int $userId, string $new): void
    {
        DB::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), Clock::nowUtc(), $userId]
        );
        Session::regenerate();
        Csrf::rotate();
        self::$user = null;
    }

    public static function reset(): void
    {
        self::$user = null;
    }
}
