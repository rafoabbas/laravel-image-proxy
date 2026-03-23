<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ImageTransformer
{
    public function needsTransform(?int $width, ?int $height, ?string $fit, int $quality, string $mimeType): bool
    {
        return $width || $height || $quality !== config('image-proxy.default_quality') || $fit || $mimeType !== 'image/webp';
    }

    public function transform(string $bytes, ?int $width, ?int $height, ?string $fit, int $quality): string
    {
        $manager = new ImageManager(new Driver);
        $image = $manager->read($bytes);

        if ($width || $height) {
            if ($fit === 'crop' && $width && $height) {
                $image->cover($width, $height);
            } else {
                $image->scaleDown($width, $height);
            }
        }

        return $image->encode(new WebpEncoder(quality: $quality))->toString();
    }
}
