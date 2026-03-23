<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ImageProxy\Services\UrlSourceResolver;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Storage::fake('image-proxy-cache');
});

function createJpegImageBytes(int $width = 10, int $height = 10): string
{
    $manager = new ImageManager(new Driver);

    return $manager->create($width, $height)->encode(new JpegEncoder)->toString();
}

test('resolves external url with allowed domain', function (): void {
    config()->set('image-proxy.allowed_domains', ['example.com']);

    $imageBytes = createJpegImageBytes();
    Http::fake([
        'https://example.com/photo.jpg' => Http::response($imageBytes, 200),
    ]);

    $resolver = new UrlSourceResolver;
    $result = $resolver->resolve('https://example.com/photo.jpg');

    expect($result)->toBeArray()
        ->and($result['source'])->toBe('url')
        ->and($result['mime_type'])->toBe('image/jpeg')
        ->and($result['bytes'])->toBe($imageBytes);
});

test('aborts with 403 for disallowed domain', function (): void {
    config()->set('image-proxy.allowed_domains', ['allowed.com']);

    $resolver = new UrlSourceResolver;
    $resolver->resolve('https://evil.com/photo.jpg');
})->throws(HttpException::class);

test('caches fetched url originals', function (): void {
    config()->set('image-proxy.allowed_domains', ['example.com']);

    $imageBytes = createJpegImageBytes();
    Http::fake([
        'https://example.com/cached.jpg' => Http::response($imageBytes, 200),
    ]);

    $resolver = new UrlSourceResolver;
    $resolver->resolve('https://example.com/cached.jpg');

    $cacheKey = 'originals/url/' . sha1('https://example.com/cached.jpg');
    Storage::disk('image-proxy-cache')->assertExists($cacheKey);
});

test('uses cached originals on second resolve', function (): void {
    config()->set('image-proxy.allowed_domains', ['example.com']);

    $imageBytes = createJpegImageBytes();
    Http::fake([
        'https://example.com/twice.jpg' => Http::response($imageBytes, 200),
    ]);

    $resolver = new UrlSourceResolver;
    $resolver->resolve('https://example.com/twice.jpg');
    $resolver->resolve('https://example.com/twice.jpg');

    Http::assertSentCount(1);
});

test('aborts with 502 when remote fetch fails', function (): void {
    config()->set('image-proxy.allowed_domains', ['example.com']);

    Http::fake([
        'https://example.com/broken.jpg' => Http::response('', 500),
    ]);

    $resolver = new UrlSourceResolver;
    $resolver->resolve('https://example.com/broken.jpg');
})->throws(HttpException::class);
