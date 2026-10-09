<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Timestamps are stored in UTC; this class converts to/from the shop timezone. */
final class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s';

    public static function tz(): DateTimeZone
    {
        $name = Settings::get('timezone', 'Asia/Manila');
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('Asia/Manila');
        }
    }

    public static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::DB_FORMAT);
    }

    public static function nowLocal(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz());
    }

    public static function todayLocal(): string
    {
        return self::nowLocal()->format('Y-m-d');
    }

    public static function toLocal(string $utc, string $format = 'M j, Y g:i A'): string
    {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(self::tz())->format($format);
    }

    /** Validate a Y-m-d string. */
    public static function isDate(?string $d): bool
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return false;
        }
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y);
    }

    /**
     * Convert an inclusive local date range to a half-open UTC range [start, end)
     * for querying UTC DATETIME columns.
     * @return array{0:string,1:string}
     */
    public static function localRangeToUtc(string $fromDate, string $toDate): array
    {
        $tz = self::tz();
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($fromDate . ' 00:00:00', $tz))->setTimezone($utc);
        $end = (new DateTimeImmutable($toDate . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc);
        return [$start->format(self::DB_FORMAT), $end->format(self::DB_FORMAT)];
    }
}
