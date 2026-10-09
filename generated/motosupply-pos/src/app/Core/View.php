<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Render a template from app/views, optionally wrapped in a layout. */
    public static function render(string $template, array $data = [], ?string $layout = 'layout/app'): void
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::capture($layout, array_merge($data, ['content' => $content]));
    }

    public static function capture(string $template, array $data = []): string
    {
        $file = MOTO_ROOT . '/app/views/' . $template . '.php';
        if (!preg_match('#^[a-z0-9_/-]+$#', $template) || !is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
