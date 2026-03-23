<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use ImageProxy\Data\ImageRequestData;
use Symfony\Component\HttpKernel\Exception\HttpException;

function makeRequest(string $path, array $query = []): ImageRequestData
{
    $request = Request::create('/img/' . $path, 'GET', $query);

    return ImageRequestData::fromRequest($request, $path);
}

test('parses blur parameter', function (): void {
    $data = makeRequest('test.jpg', ['blur' => '10']);

    expect($data->blur)->toBe(10);
});

test('clamps blur to max_blur', function (): void {
    config()->set('image-proxy.max_blur', 50);
    $data = makeRequest('test.jpg', ['blur' => '999']);

    expect($data->blur)->toBe(50);
});

test('clamps blur minimum to zero', function (): void {
    $data = makeRequest('test.jpg', ['blur' => '-5']);

    expect($data->blur)->toBe(0);
});

test('parses sharpen parameter', function (): void {
    $data = makeRequest('test.jpg', ['sharpen' => '15']);

    expect($data->sharpen)->toBe(15);
});

test('clamps sharpen to 0-100', function (): void {
    $data = makeRequest('test.jpg', ['sharpen' => '200']);

    expect($data->sharpen)->toBe(100);
});

test('parses grayscale parameter', function (): void {
    $data = makeRequest('test.jpg', ['grayscale' => '1']);

    expect($data->grayscale)->toBeTrue();
});

test('grayscale defaults to false', function (): void {
    $data = makeRequest('test.jpg');

    expect($data->grayscale)->toBeFalse();
});

test('parses brightness parameter', function (): void {
    $data = makeRequest('test.jpg', ['brightness' => '30']);

    expect($data->brightness)->toBe(30);
});

test('clamps brightness to -100 to 100', function (): void {
    $over = makeRequest('test.jpg', ['brightness' => '200']);
    $under = makeRequest('test.jpg', ['brightness' => '-200']);

    expect($over->brightness)->toBe(100);
    expect($under->brightness)->toBe(-100);
});

test('parses contrast parameter', function (): void {
    $data = makeRequest('test.jpg', ['contrast' => '-20']);

    expect($data->contrast)->toBe(-20);
});

test('clamps contrast to -100 to 100', function (): void {
    $over = makeRequest('test.jpg', ['contrast' => '150']);

    expect($over->contrast)->toBe(100);
});

test('parses rotate parameter', function (): void {
    $data = makeRequest('test.jpg', ['rotate' => '90']);

    expect($data->rotate)->toBe(90);
});

test('rotate wraps around with modulo 360', function (): void {
    $data = makeRequest('test.jpg', ['rotate' => '450']);

    expect($data->rotate)->toBe(90);
});

test('parses flip parameter with valid values', function (): void {
    expect(makeRequest('test.jpg', ['flip' => 'h'])->flip)->toBe('h');
    expect(makeRequest('test.jpg', ['flip' => 'v'])->flip)->toBe('v');
    expect(makeRequest('test.jpg', ['flip' => 'both'])->flip)->toBe('both');
});

test('rejects invalid flip value', function (): void {
    $data = makeRequest('test.jpg', ['flip' => 'diagonal']);

    expect($data->flip)->toBeNull();
});

test('parses watermark parameter', function (): void {
    $data = makeRequest('test.jpg', ['watermark' => 'watermarks/logo.png']);

    expect($data->watermark)->toBe('watermarks/logo.png');
});

test('rejects watermark with path traversal', function (): void {
    makeRequest('test.jpg', ['watermark' => '../etc/passwd']);
})->throws(HttpException::class);

test('rejects watermark with null byte', function (): void {
    makeRequest('test.jpg', ['watermark' => "logo\0.png"]);
})->throws(HttpException::class);

test('parses watermark position', function (): void {
    $data = makeRequest('test.jpg', ['watermark' => 'logo.png', 'watermark_position' => 'top-left']);

    expect($data->watermarkPosition)->toBe('top-left');
});

test('defaults watermark position to bottom-right for invalid value', function (): void {
    $data = makeRequest('test.jpg', ['watermark' => 'logo.png', 'watermark_position' => 'invalid']);

    expect($data->watermarkPosition)->toBe('bottom-right');
});

test('parses watermark alpha', function (): void {
    $data = makeRequest('test.jpg', ['watermark' => 'logo.png', 'watermark_alpha' => '80']);

    expect($data->watermarkAlpha)->toBe(80);
});

test('clamps watermark alpha to 0-100', function (): void {
    $data = makeRequest('test.jpg', ['watermark' => 'logo.png', 'watermark_alpha' => '200']);

    expect($data->watermarkAlpha)->toBe(100);
});

test('parses focal point parameters', function (): void {
    $data = makeRequest('test.jpg', ['focal_x' => '0.3', 'focal_y' => '0.7']);

    expect($data->focalX)->toBe(0.3);
    expect($data->focalY)->toBe(0.7);
});

test('clamps focal point to 0.0-1.0', function (): void {
    $data = makeRequest('test.jpg', ['focal_x' => '2.0', 'focal_y' => '-0.5']);

    expect($data->focalX)->toBe(1.0);
    expect($data->focalY)->toBe(0.0);
});

test('parses border radius parameter', function (): void {
    $data = makeRequest('test.jpg', ['border_radius' => '20']);

    expect($data->borderRadius)->toBe(20);
});

test('clamps border radius to max', function (): void {
    config()->set('image-proxy.max_border_radius', 1000);
    $data = makeRequest('test.jpg', ['border_radius' => '2000']);

    expect($data->borderRadius)->toBe(1000);
});

test('parses padding parameter', function (): void {
    $data = makeRequest('test.jpg', ['padding' => '15']);

    expect($data->padding)->toBe(15);
});

test('clamps padding to max', function (): void {
    config()->set('image-proxy.max_padding', 500);
    $data = makeRequest('test.jpg', ['padding' => '999']);

    expect($data->padding)->toBe(500);
});

test('parses background hex color', function (): void {
    $data = makeRequest('test.jpg', ['bg' => 'ff0000']);

    expect($data->background)->toBe('ff0000');
});

test('parses background hex with hash prefix', function (): void {
    $data = makeRequest('test.jpg', ['bg' => '#00ff00']);

    expect($data->background)->toBe('00ff00');
});

test('parses short hex color', function (): void {
    $data = makeRequest('test.jpg', ['bg' => 'f00']);

    expect($data->background)->toBe('f00');
});

test('rejects invalid hex color', function (): void {
    $data = makeRequest('test.jpg', ['bg' => 'xyz']);

    expect($data->background)->toBeNull();
});

test('rejects hex color with script injection', function (): void {
    $data = makeRequest('test.jpg', ['bg' => '<script>']);

    expect($data->background)->toBeNull();
});

test('blur is null when not provided', function (): void {
    $data = makeRequest('test.jpg');

    expect($data->blur)->toBeNull();
});

test('sharpen is null when not provided', function (): void {
    $data = makeRequest('test.jpg');

    expect($data->sharpen)->toBeNull();
});

test('rotate is null when not provided', function (): void {
    $data = makeRequest('test.jpg');

    expect($data->rotate)->toBeNull();
});

test('flip is null when not provided', function (): void {
    $data = makeRequest('test.jpg');

    expect($data->flip)->toBeNull();
});
