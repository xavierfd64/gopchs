<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;

/**
 * Customisable colours (primary/accent and sidebar), emitted as CSS custom properties.
 * Text colours on top of them are chosen automatically for contrast; status colours
 * (errors, warnings, success) are fixed so they always stay recognisable.
 */
final class Theme
{
    public const DEFAULT_PRIMARY = '#dd4a2b';
    public const DEFAULT_SIDEBAR = '#1f2024';

    public static function valid(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
    }

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    public static function luminance(string $hex): float
    {
        $c = array_map(static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Text colour for labels on $bg: white when it reaches 3:1 (labels on buttons and in the
     * navigation are bold, where WCAG's large-text threshold of 3:1 applies), otherwise near-black.
     */
    public static function textOn(string $bg): string
    {
        return self::contrast($bg, '#ffffff') >= 3.0 ? '#ffffff' : '#16171a';
    }

    /** Higher-contrast choice of white / near-black (for normal-weight navigation text). */
    public static function bestText(string $bg): string
    {
        return self::contrast($bg, '#ffffff') >= self::contrast($bg, '#16171a') ? '#ffffff' : '#16171a';
    }

    private static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);
        $m = static fn (int $a, int $b): int => (int) round($a + ($b - $a) * $amount);
        return sprintf('#%02x%02x%02x', $m($r1, $r2), $m($g1, $g2), $m($b1, $b2));
    }

    /** Returns an error message, or null when the pair is acceptable. */
    public static function validate(string $primary, string $sidebar): ?string
    {
        if (!self::valid($primary) || !self::valid($sidebar)) {
            return 'Colours must be in #RRGGBB format.';
        }
        if (self::contrast($primary, '#ffffff') < 3.0) {
            return 'The primary colour is too light to read on the white background (links, borders). Choose a darker shade.';
        }
        if (self::contrast($primary, self::textOn($primary)) < 3.0) {
            return 'Button text would be hard to read on this primary colour. Choose a darker or lighter shade.';
        }
        if (self::contrast($sidebar, self::bestText($sidebar)) < 4.5) {
            return 'Navigation text would be hard to read on this sidebar colour. Choose a darker or lighter shade.';
        }
        return null;
    }

    public static function css(): string
    {
        $p = Settings::get('theme_primary', self::DEFAULT_PRIMARY);
        $s = Settings::get('theme_sidebar', self::DEFAULT_SIDEBAR);
        if (self::validate($p, $s) !== null) {
            [$p, $s] = [self::DEFAULT_PRIMARY, self::DEFAULT_SIDEBAR];
        }
        $onSidebar = self::bestText($s);
        $dark = $onSidebar === '#ffffff';
        return ":root {\n"
            . "  --accent: $p;\n"
            . '  --accent-hover: ' . self::mix($p, '#000000', 0.12) . ";\n"
            . '  --accent-soft: ' . self::mix($p, '#ffffff', 0.9) . ";\n"
            . '  --on-accent: ' . self::textOn($p) . ";\n"
            . "  --sidebar: $s;\n"
            . '  --sidebar-2: ' . self::mix($s, $dark ? '#ffffff' : '#000000', 0.08) . ";\n"
            . "  --on-sidebar: $onSidebar;\n"
            . '  --sidebar-text: ' . self::mix($onSidebar, $s, 0.22) . ";\n"
            . '  --sidebar-muted: ' . self::mix($onSidebar, $s, 0.4) . ";\n"
            . "}\n";
    }

    public static function version(): string
    {
        return substr(md5(Settings::get('theme_primary') . Settings::get('theme_sidebar')), 0, 8);
    }
}
