<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Photos privées, stockées dans /backend/var/profile-photos/<user_id>/avatar.webp. */
final class ProfilePhotoStorage
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const MAX_PIXELS = 12_000_000;
    private const MAX_SIDE = 640;
    private const MAX_WEBP_BYTES = 200 * 1024;

    public function path(int $userId): string
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('Identifiant utilisateur invalide.');
        }
        return dirname(__DIR__, 2) . '/var/profile-photos/' . $userId . '/avatar.webp';
    }

    public function exists(int $userId): bool
    {
        return is_file($this->path($userId));
    }

    /** Décode puis réencode l'image : pas de SVG, pas de métadonnées EXIF, dimensions limitées. */
    public function save(int $userId, UploadedFile $upload): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new \RuntimeException('Extension PHP GD avec support WebP indisponible.');
        }
        if (!$upload->isValid() || !is_int($upload->getSize()) || $upload->getSize() <= 0 || $upload->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Photo invalide ou supérieure à 5 Mo.');
        }
        $sourcePath = $upload->getPathname();
        $info = @getimagesize($sourcePath);
        if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
            throw new \InvalidArgumentException('Image non reconnue.');
        }
        [$width, $height, $type] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Image trop grande : maximum 12 millions de pixels avant optimisation.');
        }
        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default => false,
        };
        if (!$source instanceof \GdImage) {
            throw new \InvalidArgumentException('Formats acceptés : JPEG, PNG et WebP.');
        }
        $ratio = min(1.0, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if (!$canvas instanceof \GdImage) {
            imagedestroy($source);
            throw new \RuntimeException('Impossible de préparer la photo.');
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        if (!imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new \RuntimeException('Impossible de redimensionner la photo.');
        }
        imagedestroy($source);

        $target = $this->path($userId);
        $folder = dirname($target);
        if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
            imagedestroy($canvas);
            throw new \RuntimeException('Dossier de photos non accessible en écriture.');
        }
        $temporary = $folder . '/.avatar-' . bin2hex(random_bytes(12)) . '.webp';
        try {
            $valid = false;
            foreach ([80, 70, 60, 48, 36] as $quality) {
                if (!@imagewebp($canvas, $temporary, $quality)) {
                    throw new \RuntimeException('Conversion WebP impossible.');
                }
                clearstatcache(true, $temporary);
                $size = filesize($temporary);
                if ($size !== false && $size <= self::MAX_WEBP_BYTES) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new \InvalidArgumentException('Photo trop complexe pour tenir sous 200 Ko. Essayez une image moins détaillée.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $target)) {
                throw new \RuntimeException('Impossible de sauvegarder la photo.');
            }
            @chmod($target, 0600);
        } finally {
            imagedestroy($canvas);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    public function remove(int $userId): void
    {
        $path = $this->path($userId);
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('Suppression de la photo impossible.');
        }
    }
}
