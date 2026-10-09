<?php
declare(strict_types=1);

namespace App\Core;

/** Shop settings stored in the `settings` table, cached per request. */
final class Settings
{
    public const DEFAULTS = [
        'shop_name' => 'MotoSupply Shop',
        'shop_address' => '',
        'shop_phone' => '',
        'shop_email' => '',
        'receipt_footer' => 'Thank you for your purchase!',
        'currency_code' => 'PHP',
        'currency_symbol' => '₱',
        'timezone' => 'Asia/Manila',
        'low_stock_threshold' => '5',
        'pos_auto_add_barcode' => '1',
        'pos_confirm_clear' => '1',
        'show_low_stock_badge' => '1',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (DB::all('SELECT setting_key, setting_value FROM settings') as $row) {
                    self::$cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (\Throwable) {
                // Database not available yet (e.g. during installation): use defaults.
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string
    {
        return (string) (self::all()[$key] ?? $default ?? '');
    }

    public static function set(array $values): void
    {
        $now = Clock::nowUtc();
        $stmt = DB::pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        foreach ($values as $k => $v) {
            $stmt->execute([$k, (string) $v, $now]);
        }
        self::$cache = null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
