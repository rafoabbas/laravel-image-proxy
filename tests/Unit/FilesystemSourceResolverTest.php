<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use ImageProxy\Services\FilesystemSourceResolver;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('image-proxy-cache');
});

test('resolves existing file on source disk', function (): void {
    $manager = new ImageManager(new Driver);
    $bytes = $manager->create(10, 10)->encode(new JpegEncoder)->toString();
    Storage::disk('public')->put('photos/test.jpg', $bytes);

    $resolver = new FilesystemSourceResolver;
    $result = $resolver->resolve('photos/test.jpg');

    expect($result)->toBeInstanceOf(\ImageProxy\Data\ImageSourceData::class)
        ->and($result->source)->toBe('disk')
        ->and($result->disk)->toBe('public')
        ->and($result->mimeType)->toBe('image/jpeg')
        ->and($result->bytes)->toBeString()->not->toBeEmpty();
});

test('returns null for non-existent file', function (): void {
    $resolver = new FilesystemSourceResolver;
    $result = $resolver->resolve('does/not/exist.jpg');

    expect($result)->toBeNull();
});
