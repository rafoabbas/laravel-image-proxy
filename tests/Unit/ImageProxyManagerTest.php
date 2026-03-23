<?php

declare(strict_types=1);

use ImageProxy\Services\ImageProxyManager;
use ImageProxy\Services\UrlSigner;

beforeEach(function (): void {
    config()->set('image-proxy.route.path', 'img');
    config()->set('image-proxy.signing.key', 'test-key');
});

test('generates unsigned url when signing disabled', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $manager = new ImageProxyManager(new UrlSigner);
    $url = $manager->url('photos/test.jpg', ['w' => 300]);

    expect($url)->toBe('/img/photos/test.jpg?w=300');
});

test('generates signed url when signing enabled', function (): void {
    config()->set('image-proxy.signing.enabled', true);

    $manager = new ImageProxyManager(new UrlSigner);
    $url = $manager->url('photos/test.jpg', ['w' => 300]);

    expect($url)->toStartWith('/img/photos/test.jpg?')
        ->and($url)->toContain('s=');
});

test('generates url without query when no params', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $manager = new ImageProxyManager(new UrlSigner);
    $url = $manager->url('photos/test.jpg');

    expect($url)->toBe('/img/photos/test.jpg');
});

test('generates srcset string', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $manager = new ImageProxyManager(new UrlSigner);
    $srcset = $manager->srcset('test.jpg', [320, 640, 1024]);

    expect($srcset)->toBe('/img/test.jpg?w=320 320w, /img/test.jpg?w=640 640w, /img/test.jpg?w=1024 1024w');
});

test('generates srcset with additional params', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $manager = new ImageProxyManager(new UrlSigner);
    $srcset = $manager->srcset('test.jpg', [320, 640], ['q' => 80]);

    expect($srcset)->toContain('q=80')
        ->and($srcset)->toContain('w=320')
        ->and($srcset)->toContain('w=640');
});

test('generates srcset tag with img element', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $manager = new ImageProxyManager(new UrlSigner);
    $tag = $manager->srcsetTag('test.jpg', [320, 640], [], '100vw', 'Test image');

    expect($tag)->toStartWith('<img ')
        ->and($tag)->toContain('srcset="')
        ->and($tag)->toContain('sizes="100vw"')
        ->and($tag)->toContain('alt="Test image"')
        ->and($tag)->toContain('loading="lazy"')
        ->and($tag)->toContain('decoding="async"');
});

test('srcset tag escapes alt text', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $manager = new ImageProxyManager(new UrlSigner);
    $tag = $manager->srcsetTag('test.jpg', [320], [], '100vw', '<script>alert("xss")</script>');

    expect($tag)->not->toContain('<script>')
        ->and($tag)->toContain('&lt;script&gt;');
});
