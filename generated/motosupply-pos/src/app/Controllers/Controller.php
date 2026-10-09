<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'layout/app'): void
    {
        View::render($template, $data, $layout);
    }

    protected function idParam(string $key = 'id', string $source = 'get'): int
    {
        $raw = $source === 'post' ? Http::post($key) : Http::query($key);
        return ctype_digit($raw) ? (int) $raw : 0;
    }

    protected function notFound(string $what = 'record'): never
    {
        http_response_code(404);
        View::render('pages/error', ['title' => 'Not found', 'message' => "The requested $what could not be found."]);
        exit;
    }

    /** Store submitted form values and errors for redisplay after a redirect. */
    protected function withOld(array $old, array $errors): void
    {
        $_SESSION['_old'] = $old;
        $_SESSION['_errors'] = $errors;
    }

    protected function takeOld(): array
    {
        $o = [$_SESSION['_old'] ?? [], $_SESSION['_errors'] ?? []];
        unset($_SESSION['_old'], $_SESSION['_errors']);
        return $o;
    }
}
