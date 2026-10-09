<?php
declare(strict_types=1);

namespace App\Core;

final class Http
{
    private static ?string $basePath = null;

    /** URL path of the application root, e.g. "" or "/pos". Works from index.php and install/. */
    public static function basePath(): string
    {
        if (self::$basePath === null) {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $dir = rtrim(dirname($script), '/');
            if (str_ends_with($dir, '/install')) {
                $dir = substr($dir, 0, -strlen('/install'));
            }
            self::$basePath = $dir === '.' ? '' : $dir;
        }
        return self::$basePath;
    }

    /**
     * HTTPS detection from server-side indicators only. Forwarded headers (X-Forwarded-Proto,
     * X-Forwarded-SSL) are honoured ONLY when the request comes from a proxy listed in
     * config 'app.trusted_proxies' (IP addresses or CIDR ranges); anyone can send those headers.
     */
    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return self::fromTrustedProxy() && self::forwardedHttps();
    }

    private static function forwardedHttps(): bool
    {
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https' || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
    }

    /** A forwarded header claims HTTPS but the sender is not a trusted proxy (used for guidance only). */
    public static function untrustedProxySaysHttps(): bool
    {
        return !self::fromTrustedProxy() && self::forwardedHttps();
    }

    public static function fromTrustedProxy(): bool
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = Config::get('app.trusted_proxies', []);
        if ($remote === '' || !is_array($trusted)) {
            return false;
        }
        foreach ($trusted as $range) {
            if (is_string($range) && self::ipInRange($remote, $range)) {
                return true;
            }
        }
        return false;
    }

    /** IPv4/IPv6 address match against a single IP or CIDR range. */
    public static function ipInRange(string $ip, string $range): bool
    {
        [$net, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /**
     * Whether HTTPS must be enforced: config 'app.force_https' (true/false) overrides; otherwise
     * the "Require HTTPS" (production) mode chosen in Settings → System Check.
     */
    public static function httpsRequired(): bool
    {
        $override = Config::get('app.force_https');
        if (is_bool($override)) {
            return $override;
        }
        return Settings::get('security_mode', 'testing') === 'production';
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        // Behind a trusted proxy, the client is the right-most untrusted X-Forwarded-For entry.
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($chain as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    $isProxy = false;
                    foreach ((array) Config::get('app.trusted_proxies', []) as $range) {
                        $isProxy = $isProxy || (is_string($range) && self::ipInRange($candidate, $range));
                    }
                    if (!$isProxy) {
                        break;
                    }
                }
            }
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** Decoded JSON request body (for fetch() API calls). */
    public static function jsonBody(): array
    {
        static $body = null;
        if ($body === null) {
            $raw = file_get_contents('php://input', false, null, 0, 1024 * 512);
            $decoded = json_decode((string) $raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        return $body;
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
        header('Cache-Control: no-store, private');
    }

    /** Flash messages survive exactly one redirect. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
