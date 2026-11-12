<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Stockage privé ; images décodées et réencodées avant publication par contrôleur. */
final class AppLogoStorage
{
    public const MAX_UPLOAD_BYTES = 900_000; // Sous le plafond Nginx/PHP déjà en place ; réduction dans Vue.
    private const MAX_PIXELS = 12_000_000;
    private const MAX_SIDE = 800;
    private const MAX_OUTPUT_BYTES = 350_000;

    public function path(string $filename): string
    {
        if (!preg_match('/^logo-[a-f0-9]{32}\.webp$/D', $filename)) {
            throw new \InvalidArgumentException('Nom du logo invalide.');
        }
        return dirname(__DIR__, 2) . '/var/branding/' . $filename;
    }

    public function save(UploadedFile $upload): string
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new \RuntimeException('Extension GD / WebP indisponible.');
        }
        if (!$upload->isValid() || !is_int($upload->getSize()) || $upload->getSize() < 1 || $upload->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Logo invalide ou trop volumineux (900 Ko après préparation).');
        }
        $info = @getimagesize($upload->getPathname());
        if (!is_array($info) || count($info) < 3) {
            throw new \InvalidArgumentException('Fichier image illisible.');
        }
        [$width, $height, $type] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Dimensions excessives (12 millions de pixels maximum).');
        }
        $image = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($upload->getPathname()),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($upload->getPathname()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($upload->getPathname()),
            default => false,
        };
        if (!$image instanceof \GdImage) {
            throw new \InvalidArgumentException('Formats acceptés : PNG, JPEG ou WebP. SVG interdit.');
        }
        $scale = min(1.0, self::MAX_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        if (!$canvas instanceof \GdImage) {
            imagedestroy($image);
            throw new \RuntimeException('Impossible de préparer le logo.');
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        imagedestroy($image);

        $dir = dirname(__DIR__, 2) . '/var/branding';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            throw new \RuntimeException('Dossier des logos non accessible en écriture.');
        }
        $name = 'logo-' . bin2hex(random_bytes(16)) . '.webp';
        $path = $this->path($name);
        $temporary = $dir . '/.upload-' . bin2hex(random_bytes(12));
        try {
            $ok = false;
            foreach ([90, 80, 70, 55, 40] as $quality) {
                if (!@imagewebp($canvas, $temporary, $quality)) {
                    throw new \RuntimeException('Conversion WebP impossible.');
                }
                $size = filesize($temporary);
                if (is_int($size) && $size > 0 && $size <= self::MAX_OUTPUT_BYTES) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new \InvalidArgumentException('Logo trop complexe. Essayez une image plus simple.');
            }
            @chmod($temporary, 0640);
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('Impossible d’enregistrer le logo.');
            }
            @chmod($path, 0640);
        } finally {
            imagedestroy($canvas);
            if (is_file($temporary)) @unlink($temporary);
        }
        return $name;
    }
}
