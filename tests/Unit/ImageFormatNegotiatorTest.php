<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use ImageProxy\Services\ImageFormatNegotiator;

test('negotiates avif when accept header includes avif and config supports it', function (): void {
    config()->set('image-proxy.formats', ['avif', 'webp']);

    $request = Request::create('/img/test.jpg', 'GET');
    $request->headers->set('Accept', 'image/avif,image/webp,image/*,*/*;q=0.8');

    $negotiator = new ImageFormatNegotiator;

    expect($negotiator->negotiate($request))->toBe('avif');
});

test('negotiates webp when accept header does not include avif', function (): void {
    config()->set('image-proxy.formats', ['avif', 'webp']);

    $request = Request::create('/img/test.jpg', 'GET');
    $request->headers->set('Accept', 'image/webp,image/*,*/*;q=0.8');

    $negotiator = new ImageFormatNegotiator;

    expect($negotiator->negotiate($request))->toBe('webp');
});

test('falls back to last configured format', function (): void {
    config()->set('image-proxy.formats', ['avif', 'webp']);

    $request = Request::create('/img/test.jpg', 'GET');
    $request->headers->set('Accept', 'text/html');

    $negotiator = new ImageFormatNegotiator;

    expect($negotiator->negotiate($request))->toBe('webp');
});

test('respects config format priority order', function (): void {
    config()->set('image-proxy.formats', ['webp']);

    $request = Request::create('/img/test.jpg', 'GET');
    $request->headers->set('Accept', 'image/avif,image/webp');

    $negotiator = new ImageFormatNegotiator;

    expect($negotiator->negotiate($request))->toBe('webp');
});

test('returns correct mime type for format', function (): void {
    $negotiator = new ImageFormatNegotiator;

    expect($negotiator->mimeType('avif'))->toBe('image/avif')
        ->and($negotiator->mimeType('webp'))->toBe('image/webp');
});
