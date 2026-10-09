<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;

/** Validates and stores product images with server-generated filenames. */
final class ImageUpload
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** Returns the relative path (uploads/products/xxx.ext) or null when no file was uploaded. */
    public static function store(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ValidationException(['image' => 'The image upload failed. Try a smaller file.']);
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['image' => 'Images must be 2 MB or smaller.']);
        }
        $tmp = (string) $file['tmp_name'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $info = @getimagesize($tmp);
        if (!isset(self::TYPES[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw new ValidationException(['image' => 'Upload a JPG, PNG, WEBP or GIF image.']);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000) {
            throw new ValidationException(['image' => 'Image dimensions are not supported.']);
        }
        $dir = MOTO_ROOT . '/uploads/products';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new ValidationException(['image' => 'The upload folder is not writable.']);
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new ValidationException(['image' => 'The image could not be saved.']);
        }
        @chmod($dir . '/' . $name, 0644);
        return 'uploads/products/' . $name;
    }

    public static function delete(?string $relative): void
    {
        if ($relative !== null && preg_match('#^uploads/products/[a-f0-9]{32}\.(jpg|png|webp|gif)$#', $relative)) {
            $path = MOTO_ROOT . '/' . $relative;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
