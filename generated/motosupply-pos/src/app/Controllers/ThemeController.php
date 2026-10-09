<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Theme;

/** Serves the saved theme colours as CSS custom properties (public: the login page uses it too). */
final class ThemeController extends Controller
{
    public function css(): void
    {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header_remove('Content-Security-Policy');
        echo Theme::css();
    }
}
