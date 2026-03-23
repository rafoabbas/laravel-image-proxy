<?php

declare(strict_types=1);

use ImageProxy\Services\MemoryGuard;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('passes for image within pixel limit', function (): void {
    config()->set('image-proxy.max_pixel_count', 25_000_000);

    $manager = new ImageManager(new Driver);
    $bytes = $manager->create(100, 100)->encode(new JpegEncoder)->toString();

    $guard = new MemoryGuard;
    $guard->check($bytes);

    expect(true)->toBeTrue(); // No exception
});

test('aborts for image exceeding pixel limit', function (): void {
    config()->set('image-proxy.max_pixel_count', 100); // Very low limit

    $manager = new ImageManager(new Driver);
    $bytes = $manager->create(50, 50)->encode(new JpegEncoder)->toString(); // 2500 pixels

    $guard = new MemoryGuard;
    $guard->check($bytes);
})->throws(HttpException::class);

test('passes for non-image data', function (): void {
    config()->set('image-proxy.max_pixel_count', 100);

    $guard = new MemoryGuard;
    $guard->check('not an image');

    expect(true)->toBeTrue(); // No exception, getimagesizefromstring returns false
});
