<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Storage;
use ImageProxy\Data\ImageRequestData;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class ImageTransformer
{
    public function needsTransform(ImageRequestData $params, string $mimeType, string $targetFormat = 'webp'): bool
    {
        $targetMime = match ($targetFormat) {
            'avif' => 'image/avif',
            default => 'image/webp',
        };

        return $params->width
            || $params->height
            || $params->quality !== config('image-proxy.default_quality')
            || $params->fit
            || $mimeType !== $targetMime
            || $params->blur !== null
            || $params->sharpen !== null
            || $params->grayscale
            || $params->brightness !== null
            || $params->contrast !== null
            || $params->rotate !== null
            || $params->flip !== null
            || $params->watermark !== null
            || $params->borderRadius !== null
            || $params->padding !== null
            || ($params->focalX !== null && $params->focalY !== null);
    }

    public function transform(ImageRequestData $params, string $bytes, string $format = 'webp'): string
    {
        $driver = match (config('image-proxy.driver', 'gd')) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };

        $manager = new ImageManager($driver);
        $image = $manager->read($bytes);

        $bgColor = $params->background ? '#' . $params->background : '#ffffff';

        // 1. Rotate
        if ($params->rotate !== null && $params->rotate !== 0) {
            $image->rotate($params->rotate, $bgColor);
        }

        // 2. Flip
        if ($params->flip !== null) {
            match ($params->flip) {
                'h' => $image->flip(),
                'v' => $image->flop(),
                'both' => $image->flip()->flop(),
                default => null,
            };
        }

        // 3. Resize / ScaleDown / Focal Point Crop
        if ($params->focalX !== null && $params->focalY !== null && $params->width && $params->height) {
            $this->cropWithFocalPoint($image, $params->width, $params->height, $params->focalX, $params->focalY);
        } elseif ($params->width || $params->height) {
            if ($params->fit === 'crop' && $params->width && $params->height) {
                $image->cover($params->width, $params->height);
            } else {
                $image->scaleDown($params->width, $params->height);
            }
        }

        // 4. Padding
        if ($params->padding !== null && $params->padding > 0) {
            $image->resizeCanvas($params->padding, $params->padding, $bgColor, 'relative');
        }

        // 5. Effects
        if ($params->blur !== null && $params->blur > 0) {
            $image->blur($params->blur);
        }

        if ($params->sharpen !== null && $params->sharpen > 0) {
            $image->sharpen($params->sharpen);
        }

        if ($params->grayscale) {
            $image->greyscale();
        }

        if ($params->brightness !== null && $params->brightness !== 0) {
            $image->brightness($params->brightness);
        }

        if ($params->contrast !== null && $params->contrast !== 0) {
            $image->contrast($params->contrast);
        }

        // 6. Border radius
        if ($params->borderRadius !== null && $params->borderRadius > 0) {
            $this->applyBorderRadius($image, $params->borderRadius);
        }

        // 7. Watermark
        if ($params->watermark !== null) {
            $this->applyWatermark($image, $params);
        }

        // 8. Encode
        $encoder = match ($format) {
            'avif' => new AvifEncoder(quality: $params->quality),
            default => new WebpEncoder(quality: $params->quality),
        };

        return $image->encode($encoder)->toString();
    }

    private function cropWithFocalPoint(ImageInterface $image, int $targetWidth, int $targetHeight, float $focalX, float $focalY): void
    {
        $origWidth = $image->width();
        $origHeight = $image->height();

        $scaleX = $targetWidth / $origWidth;
        $scaleY = $targetHeight / $origHeight;
        $scale = max($scaleX, $scaleY);

        $scaledWidth = (int) ceil($origWidth * $scale);
        $scaledHeight = (int) ceil($origHeight * $scale);

        $image->resize($scaledWidth, $scaledHeight);

        $focalPxX = (int) round($focalX * $scaledWidth);
        $focalPxY = (int) round($focalY * $scaledHeight);

        $offsetX = max(0, min($focalPxX - (int) ($targetWidth / 2), $scaledWidth - $targetWidth));
        $offsetY = max(0, min($focalPxY - (int) ($targetHeight / 2), $scaledHeight - $targetHeight));

        $image->crop($targetWidth, $targetHeight, $offsetX, $offsetY);
    }

    private function applyBorderRadius(ImageInterface $image, int $radius): void
    {
        $width = $image->width();
        $height = $image->height();

        $radius = min($radius, (int) ($width / 2), (int) ($height / 2));

        if ($radius <= 0) {
            return;
        }

        $driver = match (config('image-proxy.driver', 'gd')) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };
        $manager = new ImageManager($driver);

        $mask = $manager->create($width, $height)->fill('000000');

        // Draw white rounded rectangle as mask
        $mask->drawCircle($radius, $radius, function ($circle) use ($radius): void {
            $circle->radius($radius)->background('ffffff');
        });
        $mask->drawCircle($width - $radius - 1, $radius, function ($circle) use ($radius): void {
            $circle->radius($radius)->background('ffffff');
        });
        $mask->drawCircle($radius, $height - $radius - 1, function ($circle) use ($radius): void {
            $circle->radius($radius)->background('ffffff');
        });
        $mask->drawCircle($width - $radius - 1, $height - $radius - 1, function ($circle) use ($radius): void {
            $circle->radius($radius)->background('ffffff');
        });

        // Fill the center rectangles
        $mask->drawRectangle($radius, 0, function ($rect) use ($width, $height, $radius): void {
            $rect->size($width - 2 * $radius, $height)->background('ffffff');
        });
        $mask->drawRectangle(0, $radius, function ($rect) use ($width, $height, $radius): void {
            $rect->size($width, $height - 2 * $radius)->background('ffffff');
        });

        $image->place($mask, 'top-left', 0, 0);
    }

    private function applyWatermark(ImageInterface $image, ImageRequestData $params): void
    {
        $disk = config('image-proxy.watermark_disk') ?? config('image-proxy.source_disk');

        if (! Storage::disk($disk)->exists($params->watermark)) {
            return;
        }

        $watermarkBytes = Storage::disk($disk)->read($params->watermark);

        $driver = match (config('image-proxy.driver', 'gd')) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };
        $manager = new ImageManager($driver);
        $watermark = $manager->read($watermarkBytes);

        // Scale watermark based on watermarkSize (% of main image width)
        $targetWidth = (int) round($image->width() * $params->watermarkSize / 100);

        if ($targetWidth > 0 && $targetWidth < $watermark->width()) {
            $watermark->scaleDown($targetWidth);
        }

        // Set opacity
        if ($params->watermarkAlpha < 100) {
            $watermark->reduceColors(256);
        }

        $position = $params->watermarkPosition;
        $padding = $params->watermarkPadding;

        $image->place($watermark, $position, $padding, $padding, $params->watermarkAlpha);
    }
}
