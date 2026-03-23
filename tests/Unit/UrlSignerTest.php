<?php

declare(strict_types=1);

use ImageProxy\Services\UrlSigner;

beforeEach(function (): void {
    config()->set('image-proxy.signing.key', 'test-secret-key');
    config()->set('image-proxy.route.path', 'img');
});

test('signs url with hmac signature', function (): void {
    $signer = new UrlSigner;
    $url = $signer->sign('photos/test.jpg', ['w' => 300, 'q' => 80]);

    expect($url)->toStartWith('/img/photos/test.jpg?')
        ->and($url)->toContain('s=')
        ->and($url)->toContain('w=300')
        ->and($url)->toContain('q=80');
});

test('verifies valid signature', function (): void {
    $signer = new UrlSigner;
    $url = $signer->sign('photos/test.jpg', ['w' => 300]);

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($signer->verify('photos/test.jpg', $query))->toBeTrue();
});

test('rejects invalid signature', function (): void {
    $signer = new UrlSigner;

    expect($signer->verify('photos/test.jpg', ['w' => '300', 's' => 'invalid']))->toBeFalse();
});

test('rejects missing signature', function (): void {
    $signer = new UrlSigner;

    expect($signer->verify('photos/test.jpg', ['w' => '300']))->toBeFalse();
});

test('signature is deterministic regardless of param order', function (): void {
    $signer = new UrlSigner;

    $url1 = $signer->sign('test.jpg', ['w' => 300, 'q' => 80]);
    $url2 = $signer->sign('test.jpg', ['q' => 80, 'w' => 300]);

    parse_str(parse_url($url1, PHP_URL_QUERY), $q1);
    parse_str(parse_url($url2, PHP_URL_QUERY), $q2);

    expect($q1['s'])->toBe($q2['s']);
});

test('different paths produce different signatures', function (): void {
    $signer = new UrlSigner;

    $url1 = $signer->sign('photo1.jpg', ['w' => 300]);
    $url2 = $signer->sign('photo2.jpg', ['w' => 300]);

    parse_str(parse_url($url1, PHP_URL_QUERY), $q1);
    parse_str(parse_url($url2, PHP_URL_QUERY), $q2);

    expect($q1['s'])->not->toBe($q2['s']);
});

test('rejects tampered parameters', function (): void {
    $signer = new UrlSigner;
    $url = $signer->sign('test.jpg', ['w' => 300]);

    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $query['w'] = '9999'; // tamper

    expect($signer->verify('test.jpg', $query))->toBeFalse();
});
