<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Http;
use App\Core\Settings;
use App\Core\ValidationException;

/** Business logo and favicon uploads (stored in uploads/branding/ with random names). */
final class Branding
{
    private const KINDS = [
        'logo' => ['max' => 1024 * 1024, 'types' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'], 'maxDim' => 3000],
        'favicon' => ['max' => 256 * 1024, 'types' => ['image/png' => 'png', 'image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico'], 'maxDim' => 512],
    ];

    public static function store(string $kind, ?array $file): string
    {
        $spec = self::KINDS[$kind] ?? null;
        if ($spec === null || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException([$kind => 'Choose an image file to upload.']);
        }
        if (!class_exists(\finfo::class)) {
            throw new ValidationException([$kind => 'Image uploads are not available on this server (PHP fileinfo extension missing).']);
        }
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new ValidationException([$kind => 'The upload failed. Try a smaller file.']);
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > $spec['max']) {
            throw new ValidationException([$kind => ucfirst($kind) . ' files must be ' . ($spec['max'] >= 1048576 ? ($spec['max'] / 1048576) . ' MB' : ($spec['max'] / 1024) . ' KB') . ' or smaller.']);
        }
        $tmp = (string) $file['tmp_name'];
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = @getimagesize($tmp);
        if (!isset($spec['types'][$mime]) || $info === false) {
            throw new ValidationException([$kind => $kind === 'logo' ? 'Upload a PNG, JPG or WEBP image.' : 'Upload a PNG or ICO icon.']);
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > $spec['maxDim'] || $info[1] > $spec['maxDim']) {
            throw new ValidationException([$kind => 'Image dimensions must be between 16 and ' . $spec['maxDim'] . ' pixels.']);
        }
        $dir = MOTO_ROOT . '/uploads/branding';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new ValidationException([$kind => 'The uploads/branding folder could not be created. Check Settings → System Check.']);
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        $name = $kind . '-' . bin2hex(random_bytes(12)) . '.' . $spec['types'][$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new ValidationException([$kind => 'The image could not be saved.']);
        }
        @chmod($dir . '/' . $name, 0644);
        $old = Settings::get($kind . '_path');
        Settings::set([$kind . '_path' => 'uploads/branding/' . $name]);
        self::deleteFile($old);
        return 'uploads/branding/' . $name;
    }

    public static function remove(string $kind): void
    {
        if (!isset(self::KINDS[$kind])) {
            return;
        }
        $old = Settings::get($kind . '_path');
        Settings::set([$kind . '_path' => '']);
        self::deleteFile($old);
    }

    private static function deleteFile(string $rel): void
    {
        if (preg_match('#^uploads/branding/(logo|favicon)-[a-f0-9]{24}\.(png|jpg|webp|ico)$#', $rel) && is_file(MOTO_ROOT . '/' . $rel)) {
            @unlink(MOTO_ROOT . '/' . $rel);
        }
    }

    /** Public URL of the logo, or null for the default "M" mark. */
    public static function logoUrl(): ?string
    {
        $p = Settings::get('logo_path');
        return $p !== '' && is_file(MOTO_ROOT . '/' . $p) ? Http::basePath() . '/' . $p : null;
    }

    public static function faviconUrl(): string
    {
        $p = Settings::get('favicon_path');
        return $p !== '' && is_file(MOTO_ROOT . '/' . $p) ? Http::basePath() . '/' . $p : asset('img/logo.svg');
    }
}
