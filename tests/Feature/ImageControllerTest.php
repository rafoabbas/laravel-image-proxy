<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use ImageProxy\Data\ImageSourceData;
use ImageProxy\Services\FilesystemSourceResolver;
use ImageProxy\Services\UrlSigner;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('image-proxy-cache');
});

function createTestImage(int $width = 100, int $height = 100): string
{
    $manager = new ImageManager(new Driver);
    $image = $manager->create($width, $height);

    return $image->encode(new JpegEncoder(quality: 85))->toString();
}

function fakeFilesystemResolver(string $disk, string $mimeType): void
{
    app()->instance(
        FilesystemSourceResolver::class,
        new class($disk, $mimeType) extends FilesystemSourceResolver
        {
            public function __construct(
                private readonly string $fakeDisk,
                private readonly string $fakeMimeType,
            ) {}

            public function resolve(string $path): ?ImageSourceData
            {
                if (! Storage::disk($this->fakeDisk)->exists($path)) {
                    return null;
                }

                return new ImageSourceData(
                    source: 'disk',
                    mimeType: $this->fakeMimeType,
                    bytes: Storage::disk($this->fakeDisk)->read($path),
                    disk: $this->fakeDisk,
                );
            }
        },
    );
}

test('returns 404 when image not found', function (): void {
    $this->get(route('image-proxy', ['path' => 'nonexistent/image.jpg']))
        ->assertNotFound();
});

test('returns 400 for path traversal', function (): void {
    $this->get(route('image-proxy', ['path' => '../etc/passwd']))
        ->assertStatus(400);
});

test('returns 400 for null byte in path', function (): void {
    $this->get(route('image-proxy', ['path' => "test\0image.jpg"]))
        ->assertStatus(400);
});

test('serves image and converts to webp', function (): void {
    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/image.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $response = $this->get(route('image-proxy', ['path' => 'test/image.jpg']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');
    $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

test('serves cached image on second request', function (): void {
    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/cached.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', ['path' => 'test/cached.jpg']))
        ->assertOk();

    $this->get(route('image-proxy', ['path' => 'test/cached.jpg']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

test('resizes image with width parameter', function (): void {
    $imageBytes = createTestImage(800, 600);
    Storage::disk('public')->put('test/resize.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $response = $this->get(route('image-proxy', ['path' => 'test/resize.jpg', 'w' => 400]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');
});

test('resizes image with crop fit', function (): void {
    $imageBytes = createTestImage(800, 600);
    Storage::disk('public')->put('test/crop.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $response = $this->get(route('image-proxy', [
        'path' => 'test/crop.jpg',
        'w' => 200,
        'h' => 200,
        'fit' => 'crop',
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');
});

test('clamps width and height to maximum', function (): void {
    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/clamp.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $response = $this->get(route('image-proxy', [
        'path' => 'test/clamp.jpg',
        'w' => 9999,
        'h' => 9999,
    ]));

    $response->assertOk();
});

test('returns 415 for unsupported mime type', function (): void {
    Storage::disk('public')->put('test/file.svg', '<svg></svg>');
    fakeFilesystemResolver('public', 'image/svg+xml');

    $this->get(route('image-proxy', ['path' => 'test/file.svg']))
        ->assertStatus(415);
});

test('serves webp image without transform when no params', function (): void {
    $manager = new ImageManager(new Driver);
    $image = $manager->create(100, 100);
    $webpBytes = $image->encode(new WebpEncoder(quality: 85))->toString();

    Storage::disk('public')->put('test/image.webp', $webpBytes);
    fakeFilesystemResolver('public', 'image/webp');

    $response = $this->get(route('image-proxy', ['path' => 'test/image.webp']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');
});

test('custom quality parameter', function (): void {
    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/quality.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $response = $this->get(route('image-proxy', [
        'path' => 'test/quality.jpg',
        'q' => 50,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');
});

test('returns 413 when image exceeds max file size', function (): void {
    config()->set('image-proxy.max_file_size', 10); // 10 bytes

    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/large.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', ['path' => 'test/large.jpg']))
        ->assertStatus(413);
});

test('returns 304 when If-None-Match matches ETag', function (): void {
    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/etag.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    // First request to populate cache
    $response = $this->get(route('image-proxy', ['path' => 'test/etag.jpg']));
    $etag = $response->headers->get('ETag');

    // Second request with matching ETag
    $this->get(route('image-proxy', ['path' => 'test/etag.jpg']), ['If-None-Match' => $etag])
        ->assertStatus(304);
});

test('caches remote disk originals locally', function (): void {
    config()->set('image-proxy.source_disk', 's3');
    config()->set('image-proxy.remote_disks', ['s3']);
    config()->set('filesystems.disks.s3', [
        'driver' => 'local',
        'root' => storage_path('app/fake-s3'),
    ]);
    Storage::fake('s3');

    $imageBytes = createTestImage();
    Storage::disk('s3')->put('uploads/photo.jpg', $imageBytes);

    // Use the real FilesystemSourceResolver for this test
    app()->instance(FilesystemSourceResolver::class, new FilesystemSourceResolver);

    $this->get(route('image-proxy', ['path' => 'uploads/photo.jpg']))
        ->assertOk();

    Storage::disk('image-proxy-cache')->assertExists('originals/uploads/photo.jpg');
});

test('includes Vary Accept header in response', function (): void {
    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/vary.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', ['path' => 'test/vary.jpg']))
        ->assertOk()
        ->assertHeader('Vary', 'Accept');
});

test('returns 403 when signing enabled and no signature provided', function (): void {
    config()->set('image-proxy.signing.enabled', true);
    config()->set('image-proxy.signing.key', 'test-secret');

    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/signed.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', ['path' => 'test/signed.jpg']))
        ->assertStatus(403);
});

test('serves image with valid signature when signing enabled', function (): void {
    config()->set('image-proxy.signing.enabled', true);
    config()->set('image-proxy.signing.key', 'test-secret');

    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/signed.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $signer = new UrlSigner;
    $url = $signer->sign('test/signed.jpg', ['w' => 300]);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

test('returns 403 when signature is tampered', function (): void {
    config()->set('image-proxy.signing.enabled', true);
    config()->set('image-proxy.signing.key', 'test-secret');

    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/tampered.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', [
        'path' => 'test/tampered.jpg',
        'w' => 300,
        's' => 'invalid-signature',
    ]))->assertStatus(403);
});

test('lqip parameter returns small image', function (): void {
    $imageBytes = createTestImage(800, 600);
    Storage::disk('public')->put('test/lqip.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $response = $this->get(route('image-proxy', ['path' => 'test/lqip.jpg', 'lqip' => 1]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    // LQIP should be much smaller than original
    $lqipSize = strlen($response->getContent());
    expect($lqipSize)->toBeLessThan(2000);
});

test('returns 422 when image exceeds max pixel count', function (): void {
    config()->set('image-proxy.max_pixel_count', 100); // Very low

    $imageBytes = createTestImage(200, 200); // 40000 pixels
    Storage::disk('public')->put('test/huge.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', ['path' => 'test/huge.jpg']))
        ->assertStatus(422);
});

test('signing middleware passes through when signing disabled', function (): void {
    config()->set('image-proxy.signing.enabled', false);

    $imageBytes = createTestImage();
    Storage::disk('public')->put('test/nosign.jpg', $imageBytes);
    fakeFilesystemResolver('public', 'image/jpeg');

    $this->get(route('image-proxy', ['path' => 'test/nosign.jpg']))
        ->assertOk();
});
