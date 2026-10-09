<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Reversible encryption for secrets the application must use later (the SMTP password).
 * Key = SHA-256 of the installation secret in the private config file, so a database copy alone
 * cannot reveal them. Uses libsodium when available, otherwise OpenSSL AES-256-GCM.
 */
final class Secrets
{
    private static function key(): string
    {
        $secret = (string) Config::get('app.secret', '');
        if (strlen($secret) < 32) {
            // Installations from 1.0 have no secret: derive one from config values that are private.
            $secret = hash('sha256', json_encode(Config::get('db', [])) . MOTO_ROOT);
        }
        return hash('sha256', 'motosupply-secrets|' . $secret, true);
    }

    public static function encrypt(string $plain): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
        }
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'o1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false) {
            return null;
        }
        if (str_starts_with($stored, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $p = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), self::key());
            return $p === false ? null : $p;
        }
        if (str_starts_with($stored, 'o1:')) {
            $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $p === false ? null : $p;
        }
        return null;
    }
}
