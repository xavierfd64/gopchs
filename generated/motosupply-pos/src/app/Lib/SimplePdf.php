<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * Small dependency-free PDF 1.4 writer (text, lines, filled rectangles) using the
 * built-in Helvetica fonts with WinAnsi encoding. Needs no extensions beyond the
 * PHP core (zlib is used for compression when available).
 *
 * Coordinates are in points from the TOP-LEFT corner of the page.
 */
final class SimplePdf
{
    public readonly float $width;
    public readonly float $height;
    /** @var list<string> */
    private array $pages = [];
    private int $current = -1;

    private const W_REGULAR = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,
        611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,
        556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    ];
    private const W_BOLD = [
        278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,
        611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,
        611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,
    ];

    public function __construct(bool $landscape = false)
    {
        // A4
        $this->width = $landscape ? 841.89 : 595.28;
        $this->height = $landscape ? 595.28 : 841.89;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $index): void
    {
        $this->current = $index;
    }

    /** Convert UTF-8 text to WinAnsi bytes. The peso sign is not in WinAnsi, so it becomes "PHP ". */
    public static function encode(string $text): string
    {
        $text = str_replace('₱', 'PHP ', $text);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? '';
        $out = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return is_string($out) ? $out : '?';
    }

    public function textWidth(string $text, float $size, bool $bold = false): float
    {
        $widths = $bold ? self::W_BOLD : self::W_REGULAR;
        $bytes = self::encode($text);
        $w = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $c = ord($bytes[$i]);
            $w += ($c >= 32 && $c <= 126) ? $widths[$c - 32] : 556;
        }
        return $w * $size / 1000;
    }

    /** Shorten text with an ellipsis so it fits in $maxWidth. */
    public function fit(string $text, float $maxWidth, float $size, bool $bold = false): string
    {
        if ($this->textWidth($text, $size, $bold) <= $maxWidth) {
            return $text;
        }
        while ($text !== '' && $this->textWidth($text . '...', $size, $bold) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '...';
    }

    /** @param 'L'|'R'|'C' $align */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $align = 'L', float $gray = 0): void
    {
        if ($align === 'R') {
            $x -= $this->textWidth($text, $size, $bold);
        } elseif ($align === 'C') {
            $x -= $this->textWidth($text, $size, $bold) / 2;
        }
        $escaped = strtr(self::encode($text), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->pages[$this->current] .= sprintf(
            "BT %.3F g /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $gray, $bold ? 'F2' : 'F1', $size, $x, $this->height - $y, $escaped
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.6): void
    {
        $this->pages[$this->current] .= sprintf(
            "%.3F G %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $gray, $width, $x1, $this->height - $y1, $x2, $this->height - $y2
        );
    }

    public function fillRect(float $x, float $y, float $w, float $h, float $gray = 0.92): void
    {
        $this->pages[$this->current] .= sprintf(
            "%.3F g %.2F %.2F %.2F %.2F re f\n",
            $gray, $x, $this->height - $y - $h, $w, $h
        );
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        $compress = function_exists('gzcompress');
        foreach ($this->pages as $content) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "$pageId 0 R";
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->width, $this->height, $contentId
            );
            $data = $compress ? gzcompress($content, 6) : $content;
            $objects[$contentId] = '<< /Length ' . strlen($data) . ($compress ? ' /Filter /FlateDecode' : '') . " >>\nstream\n"
                . $data . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
