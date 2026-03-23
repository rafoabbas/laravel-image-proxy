<?php

declare(strict_types=1);

use ImageProxy\Data\ImageRequestData;
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

function makeParams(array $overrides = []): ImageRequestData
{
    return new ImageRequestData(
        path: $overrides['path'] ?? 'test.jpg',
        width: $overrides['width'] ?? null,
        height: $overrides['height'] ?? null,
        fit: $overrides['fit'] ?? null,
        quality: $overrides['quality'] ?? 85,
        lqip: $overrides['lqip'] ?? false,
        blur: $overrides['blur'] ?? null,
        sharpen: $overrides['sharpen'] ?? null,
        grayscale: $overrides['grayscale'] ?? false,
        brightness: $overrides['brightness'] ?? null,
        contrast: $overrides['contrast'] ?? null,
        rotate: $overrides['rotate'] ?? null,
        flip: $overrides['flip'] ?? null,
        watermark: $overrides['watermark'] ?? null,
        watermarkPosition: $overrides['watermarkPosition'] ?? 'bottom-right',
        watermarkAlpha: $overrides['watermarkAlpha'] ?? 50,
        watermarkSize: $overrides['watermarkSize'] ?? 25,
        watermarkPadding: $overrides['watermarkPadding'] ?? 10,
        focalX: $overrides['focalX'] ?? null,
        focalY: $overrides['focalY'] ?? null,
        borderRadius: $overrides['borderRadius'] ?? null,
        padding: $overrides['padding'] ?? null,
        background: $overrides['background'] ?? null,
    );
}

// --- needsTransform tests ---

test('needs transform when width is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['width' => 400]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when height is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['height' => 300]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when fit is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['fit' => 'crop']);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when quality differs from default', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['quality' => 50]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when mime type is not webp', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams();

    expect($transformer->needsTransform($params, 'image/jpeg'))->toBeTrue();
});

test('does not need transform for default webp without params', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams();

    expect($transformer->needsTransform($params, 'image/webp'))->toBeFalse();
});

test('needs transform when blur is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['blur' => 10]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when sharpen is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['sharpen' => 10]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when grayscale is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['grayscale' => true]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when brightness is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['brightness' => 20]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when contrast is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['contrast' => -10]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when rotate is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['rotate' => 90]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when flip is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['flip' => 'h']);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when watermark is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['watermark' => 'watermarks/logo.png']);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when border radius is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['borderRadius' => 20]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

test('needs transform when padding is set', function (): void {
    $transformer = new ImageTransformer;
    $params = makeParams(['padding' => 10]);

    expect($transformer->needsTransform($params, 'image/webp'))->toBeTrue();
});

// --- transform tests ---

test('transforms image with width', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $result = $transformer->transform(makeParams(['width' => 400]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with crop', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $result = $transformer->transform(makeParams(['width' => 200, 'height' => 200, 'fit' => 'crop']), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with different quality levels', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $highQuality = $transformer->transform(makeParams(['quality' => 100]), $bytes);
    $lowQuality = $transformer->transform(makeParams(['quality' => 40]), $bytes);

    expect($highQuality)->toBeString()->not->toBeEmpty();
    expect($lowQuality)->toBeString()->not->toBeEmpty();
    expect($highQuality)->not->toBe($lowQuality);
});

test('transforms image with blur', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['blur' => 10]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with grayscale', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['grayscale' => true]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with rotate', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['rotate' => 90]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with flip horizontal', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['flip' => 'h']), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with flip vertical', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['flip' => 'v']), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with brightness', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['brightness' => 30]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with contrast', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['contrast' => -20]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with sharpen', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['sharpen' => 15]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with focal point crop', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(800, 600);

    $result = $transformer->transform(makeParams([
        'width' => 200,
        'height' => 200,
        'focalX' => 0.3,
        'focalY' => 0.7,
    ]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with padding', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['padding' => 20, 'background' => 'ff0000']), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});

test('transforms image with border radius', function (): void {
    $transformer = new ImageTransformer;
    $bytes = createJpegBytes(100, 100);

    $result = $transformer->transform(makeParams(['borderRadius' => 20]), $bytes);

    expect($result)->toBeString()->not->toBeEmpty();
});
