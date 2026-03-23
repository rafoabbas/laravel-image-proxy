<?php

declare(strict_types=1);

use ImageProxy\Services\ImageTransformer;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

function createJpegBytes(int $width = 100, int $height = 100): string
{
    $manager = new ImageManager(new Driver);
    $image = $manager->create($width, $height);

    return $image->encode(new JpegEncoder(quality: 85))->toString();
}

test('needs transform when width is set', function (): void {
    $transformer = new ImageTransformer;

    expect($transformer->needsTransform(400, null, null, 85, 'image/webp'))->toBeTrue();
});

test('needs transform when height is set', function (): void {
    $transformer = new ImageTransformer;

    expect($transformer->needsTransform(null, 300, null, 85, 'image/webp'))->toBeTrue();
});

test('needs transform when fit is set', function (): void {
    $transformer = new ImageTransformer;

    expect($transformer->needsTransform(null, null, 'crop', 85, 'image/webp'))->toBeTrue();
});

test('needs transform when quality differs from default', function (): void {
    $transformer = new ImageTransformer;

    expect($transformer->needsTransform(null, null, null, 50, 'image/webp'))->toBeTrue();
});

test('needs transform when mime type is not webp', function (): void {
    $transformer = new ImageTransformer;

    expect($transformer->needsTransform(null, null, null, 85, 'image/jpeg'))->toBeTrue();
});

test('does not need transform for default webp without params', function (): void {
    $transformer = new ImageTransformer;

    expect($transformer->needsTransform(null, null, null, 85, 'image/webp'))->toBeFalse();
});

test('transforms image with width', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $result = $transformer->transform($bytes, 400, null, null, 85);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with crop', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $result = $transformer->transform($bytes, 200, 200, 'crop', 85);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with different quality levels', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $highQuality = $transformer->transform($bytes, null, null, null, 100);
    $lowQuality = $transformer->transform($bytes, null, null, null, 40);

    expect($highQuality)->toBeString()->not->toBeEmpty();
    expect($lowQuality)->toBeString()->not->toBeEmpty();
    expect($highQuality)->not->toBe($lowQuality);
});
