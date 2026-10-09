<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\ValidationException;

/**
 * Supervisor approval for voids. The approver enters THEIR username and THEIR separate void PIN
 * (not a login password). PINs are stored only as password hashes. Failed attempts are
 * rate-limited per approver and per IP address.
 */
final class VoidAuthorization
{
    public const MIN_LENGTH = 6;
    public const MAX_LENGTH = 12;
    public const MAX_APPROVER_FAILURES = 5;
    public const MAX_IP_FAILURES = 15;
    public const LOCK_MINUTES = 15;

    /** PIN policy: 6–12 digits, not all the same digit, not a straight sequence. */
    public static function pinPolicyError(string $pin): ?string
    {
        if (!preg_match('/^\d{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}$/', $pin)) {
            return 'The void PIN must be ' . self::MIN_LENGTH . ' to ' . self::MAX_LENGTH . ' digits.';
        }
        if (preg_match('/^(\d)\1+$/', $pin)) {
            return 'The void PIN cannot repeat a single digit.';
        }
        $up = $down = true;
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            $d = (ord($pin[$i]) - ord($pin[$i - 1]) + 10) % 10;
            $up = $up && $d === 1;
            $down = $down && $d === 9;
        }
        if ($up || $down) {
            return 'The void PIN cannot be a simple sequence such as 123456.';
        }
        return null;
    }

    public static function setPin(int $userId, string $pin): void
    {
        DB::run('UPDATE users SET void_pin_hash = ?, void_pin_set_at = ?, updated_at = ? WHERE id = ?',
            [password_hash($pin, PASSWORD_DEFAULT), Clock::nowUtc(), Clock::nowUtc(), $userId]);
    }

    public static function clearPin(int $userId): void
    {
        DB::run('UPDATE users SET void_pin_hash = NULL, void_pin_set_at = NULL, updated_at = ? WHERE id = ?', [Clock::nowUtc(), $userId]);
    }

    /**
     * Verify an approver's credential. Returns the approver (id, username) or throws with a
     * generic message. Every attempt is recorded for rate limiting.
     */
    public static function verify(string $username, string $pin, string $ip): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $approver = DB::one('SELECT id, username, void_pin_hash FROM users WHERE username = ? AND is_active = 1', [mb_substr(trim($username), 0, 50)]);
        $approverId = $approver === null ? null : (int) $approver['id'];

        $ipFails = (int) DB::value('SELECT COUNT(*) FROM pin_attempts WHERE success = 0 AND ip_address = ? AND attempted_at >= ?', [$ip, $since]);
        $userFails = $approverId === null ? 0 : (int) DB::value(
            'SELECT COUNT(*) FROM pin_attempts WHERE success = 0 AND approver_id = ? AND attempted_at >= ?', [$approverId, $since]);
        if ($ipFails >= self::MAX_IP_FAILURES || $userFails >= self::MAX_APPROVER_FAILURES) {
            self::record($approverId, $ip, false);
            throw new ValidationException(['approval' => 'Too many incorrect approval attempts. Void approval is locked for ' . self::LOCK_MINUTES . ' minutes.']);
        }

        $hash = $approver['void_pin_hash'] ?? null;
        $ok = is_string($hash) && $hash !== '' && password_verify($pin, $hash);
        if ($ok) {
            $perms = Auth::load($approverId)['permissions'] ?? [];
            $ok = in_array('sales.void.approve', $perms, true);
        } else {
            password_verify($pin, '$2y$10$v349YXR37Ti.W5Xo4MhIfuuiFimNjym3NPI6gnBhWOYss.893Bixu'); // similar timing
        }
        self::record($approverId, $ip, $ok);
        if (!$ok) {
            throw new ValidationException(['approval' => 'Approval failed: the approver name or void PIN is incorrect, or that user cannot approve voids.']);
        }
        return ['id' => $approverId, 'username' => $approver['username']];
    }

    private static function record(?int $approverId, string $ip, bool $success): void
    {
        DB::run('INSERT INTO pin_attempts (approver_id, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)',
            [$approverId, $ip, $success ? 1 : 0, Clock::nowUtc()]);
    }
}
