<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use ImageProxy\Services\ImageResponseBuilder;

test('responds with correct headers', function (): void {
    $builder = new ImageResponseBuilder;
    $request = Request::create('/img/test.jpg');

    $response = $builder->respond($request, 'image-bytes', 'abc123', 31536000);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('ETag'))->toBe('"abc123"')
        ->and($response->getContent())->toBe('image-bytes');
});

test('includes Last-Modified header when provided', function (): void {
    $builder = new ImageResponseBuilder;
    $request = Request::create('/img/test.jpg');
    $timestamp = mktime(12, 0, 0, 1, 15, 2025);

    $response = $builder->respond($request, 'bytes', 'key', 3600, $timestamp);

    expect($response->headers->has('Last-Modified'))->toBeTrue();
});

test('omits Last-Modified header when not provided', function (): void {
    $builder = new ImageResponseBuilder;
    $request = Request::create('/img/test.jpg');

    $response = $builder->respond($request, 'bytes', 'key', 3600);

    expect($response->headers->has('Last-Modified'))->toBeFalse();
});

test('returns 304 when If-None-Match matches ETag', function (): void {
    $builder = new ImageResponseBuilder;
    $request = Request::create('/img/test.jpg', 'GET', [], [], [], [
        'HTTP_IF_NONE_MATCH' => '"abc123"',
    ]);

    $response = $builder->respond($request, 'image-bytes', 'abc123', 31536000);

    expect($response->getStatusCode())->toBe(304)
        ->and($response->getContent())->toBe('');
});

test('returns 200 when If-None-Match does not match ETag', function (): void {
    $builder = new ImageResponseBuilder;
    $request = Request::create('/img/test.jpg', 'GET', [], [], [], [
        'HTTP_IF_NONE_MATCH' => '"old-key"',
    ]);

    $response = $builder->respond($request, 'image-bytes', 'new-key', 31536000);

    expect($response->getStatusCode())->toBe(200);
});

test('returns 304 when If-Modified-Since is after last modified', function (): void {
    $builder = new ImageResponseBuilder;
    $lastModified = mktime(12, 0, 0, 1, 10, 2025);
    $request = Request::create('/img/test.jpg', 'GET', [], [], [], [
        'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', mktime(12, 0, 0, 1, 15, 2025)) . ' GMT',
    ]);

    $response = $builder->respond($request, 'image-bytes', 'key', 31536000, $lastModified);

    expect($response->getStatusCode())->toBe(304);
});

test('returns 200 when If-Modified-Since is before last modified', function (): void {
    $builder = new ImageResponseBuilder;
    $lastModified = mktime(12, 0, 0, 1, 20, 2025);
    $request = Request::create('/img/test.jpg', 'GET', [], [], [], [
        'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', mktime(12, 0, 0, 1, 10, 2025)) . ' GMT',
    ]);

    $response = $builder->respond($request, 'image-bytes', 'key', 31536000, $lastModified);

    expect($response->getStatusCode())->toBe(200);
});
