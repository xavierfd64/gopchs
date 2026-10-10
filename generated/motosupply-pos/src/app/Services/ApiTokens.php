<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\DB;

/**
 * Short-lived sign-in tokens for the cashier desktop app.
 *
 *  - 256-bit random tokens; only their SHA-256 hash is stored.
 *  - Expire after the web session idle time without use (default 30 minutes) and at most
 *    12 hours after sign-in (one shift). The cashier then signs in again.
 *  - Revoked on sign-out; useless once the account is deactivated (checked on every request).
 *  - Each token has a per-minute request budget.
 */
final class ApiTokens
{
    public const MAX_LIFETIME_SECONDS = 12 * 3600;
    public const REQUESTS_PER_MINUTE = 300;

    public static function idleSeconds(): int
    {
        return max(300, (int) Config::get('app.session_idle_seconds', 1800));
    }

    /** Issue a token. Returns [token, expires_at UTC]. */
    public static function issue(int $userId, string $device, string $ip): array
    {
        $token = 'mst_' . bin2hex(random_bytes(32));
        $now = Clock::nowUtc();
        $expires = gmdate('Y-m-d H:i:s', time() + self::idleSeconds());
        DB::run(
            'INSERT INTO api_tokens (user_id, token_hash, device_name, ip_address, created_at, last_used_at, expires_at, window_start, window_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
            [$userId, hash('sha256', $token), mb_substr($device, 0, 100), mb_substr($ip, 0, 45), $now, $now, $expires, $now]
        );
        // Opportunistic cleanup of old tokens (no cron needed).
        if (random_int(1, 20) === 1) {
            DB::run('DELETE FROM api_tokens WHERE expires_at < ? OR revoked_at IS NOT NULL AND revoked_at < ?',
                [gmdate('Y-m-d H:i:s', time() - 86400 * 7), gmdate('Y-m-d H:i:s', time() - 86400 * 7)]);
        }
        return [$token, $expires];
    }

    /**
     * Validate a token and extend its idle expiry. Returns the token row, 'expired', 'rate' or null.
     * @return array|string|null
     */
    public static function check(string $token): array|string|null
    {
        if (!preg_match('/^mst_[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = DB::one('SELECT * FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }
        $now = time();
        if (strtotime($row['expires_at'] . ' UTC') <= $now || strtotime($row['created_at'] . ' UTC') + self::MAX_LIFETIME_SECONDS <= $now) {
            return 'expired';
        }
        // Per-minute request budget.
        $windowFresh = strtotime($row['window_start'] . ' UTC') > $now - 60;
        if ($windowFresh && (int) $row['window_count'] >= self::REQUESTS_PER_MINUTE) {
            return 'rate';
        }
        $newExpiry = min($now + self::idleSeconds(), strtotime($row['created_at'] . ' UTC') + self::MAX_LIFETIME_SECONDS);
        DB::run(
            'UPDATE api_tokens SET last_used_at = ?, expires_at = ?, window_start = ?, window_count = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $newExpiry),
                $windowFresh ? $row['window_start'] : gmdate('Y-m-d H:i:s', $now), $windowFresh ? (int) $row['window_count'] + 1 : 1, $row['id']]
        );
        $row['expires_at'] = gmdate('Y-m-d H:i:s', $newExpiry);
        return $row;
    }

    public static function revoke(int $tokenId): void
    {
        DB::run('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::nowUtc(), $tokenId]);
    }

    /** Revoke every token of a user (deactivation, password reset). */
    public static function revokeAllForUser(int $userId): void
    {
        DB::run('UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::nowUtc(), $userId]);
    }
}
