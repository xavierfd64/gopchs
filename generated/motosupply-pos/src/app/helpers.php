<?php
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Money;

/** Escape a value for safe HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an application URL for a route, e.g. url('products.edit', ['id' => 5]). */
function url(string $route = 'dashboard', array $params = []): string
{
    $query = array_merge(['r' => $route], $params);
    return Http::basePath() . '/index.php?' . http_build_query($query);
}

/** URL of a public asset with a cache-busting version parameter. */
function asset(string $path): string
{
    $file = MOTO_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : MOTO_VERSION;
    return Http::basePath() . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

/** Format an exact money amount (decimal string or integer centavos) for display. */
function money(string|int $amount, bool $isCents = false): string
{
    $cents = $isCents ? (int) $amount : Money::toCents((string) $amount);
    return Money::format($cents);
}

/** Format a UTC database timestamp in the shop timezone. */
function local_time(?string $utc, string $format = 'M j, Y g:i A'): string
{
    return $utc === null || $utc === '' ? '' : Clock::toLocal($utc, $format);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"/></svg>';
}

/** Two-letter label for image-less product thumbnails (first letters of the first word). */
function initials(string $text): string
{
    $words = preg_split('/[\s\-_\/]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    return mb_strtoupper(mb_substr($words[0], 0, 2));
}

/** Deterministic tint class (t0–t5) for a thumbnail, based on its label. */
function tint(string $text): string
{
    return 't' . (crc32($text) % 6);
}

/** Number of active products at or below their low-stock threshold (including out of stock). */
function low_stock_count(): int
{
    static $count = null;
    if ($count === null) {
        $count = (int) App\Core\DB::value(
            'SELECT COUNT(*) FROM products p WHERE p.is_active = 1 AND p.stock_qty <= ' . App\Services\ProductService::thresholdSql()
        );
    }
    return $count;
}

/** Render pagination links preserving the current query string. */
function pagination(int $page, int $pages, int $total, string $noun, int $perPage, int $shown): string
{
    $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
    $to = $from === 0 ? 0 : $from + $shown - 1;
    $params = $_GET;
    $link = static function (int $p) use ($params): string {
        $params['page'] = $p;
        return App\Core\Http::basePath() . '/index.php?' . http_build_query($params);
    };
    $html = '<div class="pager"><span class="pager-info">Showing ' . number_format($from) . '–' . number_format($to)
        . ' of ' . number_format($total) . ' ' . e($noun) . '</span><div class="pager-links">';
    $html .= $page > 1
        ? '<a class="btn btn-sm" href="' . e($link($page - 1)) . '">Previous</a>'
        : '<span class="btn btn-sm is-disabled" aria-disabled="true">Previous</span>';
    $html .= '<span class="pager-page">Page ' . $page . ' of ' . $pages . '</span>';
    $html .= $page < $pages
        ? '<a class="btn btn-sm" href="' . e($link($page + 1)) . '">Next</a>'
        : '<span class="btn btn-sm is-disabled" aria-disabled="true">Next</span>';
    return $html . '</div></div>';
}

/** Initials of a person's name for avatars, e.g. "Jamie Dela Cruz" -> "JD". */
function person_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $s = count($words) > 1 ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1) : mb_substr($words[0], 0, 2);
    return mb_strtoupper($s);
}

/** "1 item", "3 items". */
function plural(int $n, string $one, ?string $many = null): string
{
    return number_format($n) . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}
