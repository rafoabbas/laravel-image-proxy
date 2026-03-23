<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ImageTransformer
{
    public function needsTransform(?int $width, ?int $height, ?string $fit, int $quality, string $mimeType, string $targetFormat = 'webp'): bool
    {
        $targetMime = match ($targetFormat) {
            'avif' => 'image/avif',
            default => 'image/webp',
        };

        return $width || $height || $quality !== config('image-proxy.default_quality') || $fit || $mimeType !== $targetMime;
    }

    public function transform(string $bytes, ?int $width, ?int $height, ?string $fit, int $quality, string $format = 'webp'): string
    {
        $driver = match (config('image-proxy.driver', 'gd')) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };

        $manager = new ImageManager($driver);
        $image = $manager->read($bytes);

        if ($width || $height) {
            if ($fit === 'crop' && $width && $height) {
                $image->cover($width, $height);
            } else {
                $image->scaleDown($width, $height);
            }
        }

        $encoder = match ($format) {
            'avif' => new AvifEncoder(quality: $quality),
            default => new WebpEncoder(quality: $quality),
        };

        return $image->encode($encoder)->toString();
    }
}
