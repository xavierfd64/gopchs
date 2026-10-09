<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Append-only audit log of sensitive operations. Never pass passwords, PINs, tokens or other
 * secrets in $details: keys that look like secrets are removed defensively.
 */
final class Audit
{
    private const SECRET_KEYS = '/pass|pin|secret|token|key|hash|smtp_password|csrf/i';

    public static function log(string $action, string $entityType = '', string|int $entityId = '', array $details = [], string $status = 'success', ?array $actor = null): void
    {
        $actor ??= Auth::user();
        try {
            DB::run(
                'INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, status, details, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $actor['id'] ?? null,
                    mb_substr((string) ($actor['username'] ?? ''), 0, 50),
                    mb_substr($action, 0, 64),
                    mb_substr($entityType, 0, 32),
                    mb_substr((string) $entityId, 0, 64),
                    $status === 'failure' ? 'failure' : 'success',
                    $details ? json_encode(self::scrub($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    Http::clientIp(),
                    Clock::nowUtc(),
                ]
            );
        } catch (\Throwable $e) {
            // Auditing must never break the operation it records; note it in the private log.
            Logger::error('Audit log write failed for ' . $action, $e);
        }
    }

    private static function scrub(array $details): array
    {
        $out = [];
        foreach ($details as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k) && !in_array($k, ['changed_keys'], true)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::scrub($v) : (is_string($v) ? mb_substr($v, 0, 500) : $v);
        }
        return $out;
    }

    /** @return array{rows:array,total:int,page:int,pages:int} */
    public static function list(array $f, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        if (($f['action'] ?? '') !== '') {
            $where[] = 'action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['action']) . '%';
        }
        if (($f['user'] ?? '') !== '') {
            $where[] = 'username = ?';
            $params[] = (string) $f['user'];
        }
        if (in_array($f['status'] ?? '', ['success', 'failure'], true)) {
            $where[] = 'status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['from_utc']) && !empty($f['to_utc'])) {
            $where[] = 'created_at >= ? AND created_at < ?';
            array_push($params, $f['from_utc'], $f['to_utc']);
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) DB::value("SELECT COUNT(*) FROM audit_log $w", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = DB::all("SELECT * FROM audit_log $w ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
