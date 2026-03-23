<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use ImageProxy\Services\ImageCacheService;

beforeEach(function (): void {
    Storage::fake('image-proxy-cache');
});

test('builds deterministic cache key from path and query', function (): void {
    $service = new ImageCacheService;

    $key1 = $service->buildCacheKey('test/image.jpg', ['w' => '400', 'q' => '80']);
    $key2 = $service->buildCacheKey('test/image.jpg', ['q' => '80', 'w' => '400']);

    expect($key1)->toBe($key2);
});

test('builds different cache keys for different paths', function (): void {
    $service = new ImageCacheService;

    $key1 = $service->buildCacheKey('test/a.jpg', ['w' => '400']);
    $key2 = $service->buildCacheKey('test/b.jpg', ['w' => '400']);

    expect($key1)->not->toBe($key2);
});

test('builds cache path with directory prefix', function (): void {
    $service = new ImageCacheService;

    $key = $service->buildCacheKey('test/image.jpg', []);
    $path = $service->buildCachePath($key);

    expect($path)->toBe(substr($key, 0, 2) . '/' . $key . '.webp');
});

test('has returns false for non-existent path', function (): void {
    $service = new ImageCacheService;

    expect($service->has('aa/nonexistent.webp'))->toBeFalse();
});

test('put and get round-trip works', function (): void {
    $service = new ImageCacheService;

    $service->put('ab/testfile.webp', 'fake-image-bytes');

    expect($service->has('ab/testfile.webp'))->toBeTrue();
    expect($service->get('ab/testfile.webp'))->toBe('fake-image-bytes');
});

test('lastModified returns null for non-existent path', function (): void {
    $service = new ImageCacheService;

    expect($service->lastModified('zz/missing.webp'))->toBeNull();
});

test('lastModified returns timestamp for existing file', function (): void {
    $service = new ImageCacheService;

    $service->put('ab/cached.webp', 'bytes');
    $lastModified = $service->lastModified('ab/cached.webp');

    expect($lastModified)->toBeInt()->toBeGreaterThan(0);
});
