<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Exact money handling. Amounts are processed as integer centavos and stored as
 * DECIMAL(12,2) strings. Floating point is never used for money.
 */
final class Money
{
    public const MAX_CENTS = 999999999999; // DECIMAL(12,2) upper bound

    /** Parse user input like "1,250.5" into centavos. Returns null when invalid or negative. */
    public static function parse(mixed $input): ?int
    {
        if (is_int($input)) {
            return $input >= 0 ? $input * 100 : null;
        }
        if (!is_string($input)) {
            return null;
        }
        $s = str_replace([',', ' ', '₱'], '', trim($input));
        if (!preg_match('/^(\d{1,10})(?:\.(\d{0,2}))?$/', $s, $m)) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $cents <= self::MAX_CENTS ? $cents : null;
    }

    /** Convert a DECIMAL string from the database (e.g. "1250.00" or "-3.50") to centavos. */
    public static function toCents(string $decimal): int
    {
        $decimal = trim($decimal);
        $neg = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-+');
        [$whole, $frac] = array_pad(explode('.', $decimal, 2), 2, '0');
        $cents = (int) $whole * 100 + (int) substr(str_pad($frac, 2, '0'), 0, 2);
        return $neg ? -$cents : $cents;
    }

    /** Convert centavos to a DECIMAL string suitable for the database. */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Human format, e.g. ₱1,250.00. */
    public static function format(int $cents, ?string $symbol = null): string
    {
        $symbol ??= Settings::get('currency_symbol', '₱');
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . $symbol . number_format(intdiv($cents, 100)) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Multiply a centavo amount by an integer quantity. */
    public static function times(int $cents, int $qty): int
    {
        return $cents * $qty;
    }

    /** Percentage of an amount in basis points (1% = 100 bp), rounded half-up to the centavo. */
    public static function percent(int $cents, int $basisPoints): int
    {
        return intdiv($cents * $basisPoints + 5000, 10000);
    }
}
