<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Secrets;
use App\Core\Settings;

/**
 * Minimal SMTP client (STARTTLS / SSL / plain, AUTH LOGIN) with MIME attachments, or PHP mail()
 * when chosen. No external libraries. Exceptions carry safe messages: the SMTP password is never
 * included in errors or logs.
 */
final class Mailer
{
    /** Extra stream context options (used by the test suite for a local TLS test server). */
    public static array $testStreamOptions = [];

    /**
     * @param list<string> $to
     * @param list<array{name:string,type:string,data:string}> $attachments
     */
    public static function send(array $to, string $subject, string $text, string $html, array $attachments = []): void
    {
        $to = array_values(array_filter(array_map('trim', $to), static fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($to === []) {
            throw new \RuntimeException('No valid recipient email address is configured.');
        }
        $from = Settings::get('mail_from_address');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Set a valid "From" email address first.');
        }
        $fromName = preg_replace('/[\r\n"]+/', ' ', Settings::get('mail_from_name', Settings::get('shop_name'))) ?? '';
        $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? '';
        [$headers, $body] = self::buildMessage($from, $fromName, $to, $subject, $text, $html, $attachments);

        if (Settings::get('mail_transport', 'smtp') === 'phpmail') {
            $h = implode("\r\n", array_filter($headers, static fn ($l) => !str_starts_with($l, 'To:') && !str_starts_with($l, 'Subject:')));
            if (!@mail(implode(', ', $to), self::encodeHeader($subject), $body, $h, '-f' . $from)) {
                throw new \RuntimeException('PHP mail() refused the message. Many hosts disable it; use SMTP instead.');
            }
            return;
        }
        self::smtp($from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function buildMessage(string $from, string $fromName, array $to, string $subject, string $text, string $html, array $attachments): array
    {
        $mixed = 'mix-' . bin2hex(random_bytes(12));
        $alt = 'alt-' . bin2hex(random_bytes(12));
        $host = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
        ];
        $b = "--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n";
        $b .= "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n";
        $b .= "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n";
        $b .= "--$alt--\r\n";
        foreach ($attachments as $a) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $a['name']);
            $b .= "--$mixed\r\nContent-Type: {$a['type']}; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($a['data'])) . "\r\n";
        }
        $b .= "--$mixed--\r\n";
        return [$headers, $b];
    }

    private static function smtp(string $from, array $to, string $message): void
    {
        $host = Settings::get('smtp_host');
        $port = (int) Settings::get('smtp_port', '587');
        $enc = Settings::get('smtp_encryption', 'tls');
        $user = Settings::get('smtp_username');
        $pass = Secrets::decrypt(Settings::get('smtp_password_enc')) ?? '';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host) || $port < 1 || $port > 65535) {
            throw new \RuntimeException('SMTP server settings are incomplete.');
        }
        $ctx = stream_context_create(['ssl' => array_merge(['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host], self::$testStreamOptions)]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new \RuntimeException("Could not connect to the SMTP server $host:$port. Check the host and port; some free hosts block outgoing mail ports.");
        }
        stream_set_timeout($fp, 20);
        try {
            self::expect($fp, [220]);
            $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            self::cmd($fp, "EHLO $ehloHost", [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('Could not start an encrypted (TLS) connection with the SMTP server.');
                }
                self::cmd($fp, "EHLO $ehloHost", [250]);
            }
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334], true);
                self::cmd($fp, base64_encode($pass), [235], true);
            }
            self::cmd($fp, "MAIL FROM:<$from>", [250]);
            foreach ($to as $rcpt) {
                self::cmd($fp, "RCPT TO:<$rcpt>", [250, 251]);
            }
            self::cmd($fp, 'DATA', [354]);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
            fwrite($fp, str_replace("\n", "\r\n", (string) $data) . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::cmd($fp, 'QUIT', [221, 250]);
        } finally {
            fclose($fp);
        }
    }

    private static function cmd($fp, string $line, array $ok, bool $secret = false): string
    {
        fwrite($fp, $line . "\r\n");
        try {
            return self::expect($fp, $ok);
        } catch (\RuntimeException $e) {
            if ($secret) {
                throw new \RuntimeException('The SMTP server rejected the username or password.');
            }
            throw $e;
        }
    }

    private static function expect($fp, array $ok): string
    {
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            $msg = trim(preg_replace('/\s+/', ' ', substr($resp, 0, 200)) ?? '');
            throw new \RuntimeException('The SMTP server replied: ' . ($msg !== '' ? $msg : 'no response (timeout)'));
        }
        return $resp;
    }
}
