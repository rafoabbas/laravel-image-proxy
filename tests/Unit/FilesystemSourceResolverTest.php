<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use ImageProxy\Services\FilesystemSourceResolver;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

beforeEach(function () {
    Storage::fake('public');
});

test('resolves existing file on source disk', function () {
    $manager = new ImageManager(new Driver);
    $bytes = $manager->create(10, 10)->encode(new JpegEncoder)->toString();
    Storage::disk('public')->put('photos/test.jpg', $bytes);

    $resolver = new FilesystemSourceResolver;
    $result = $resolver->resolve('photos/test.jpg');

    expect($result)->toBeArray()
        ->and($result['disk'])->toBe('public')
        ->and($result['mime_type'])->toBe('image/jpeg');
});

test('returns null for non-existent file', function () {
    $resolver = new FilesystemSourceResolver;
    $result = $resolver->resolve('does/not/exist.jpg');

    expect($result)->toBeNull();
});
