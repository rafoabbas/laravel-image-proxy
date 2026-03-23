<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use ImageProxy\Services\FilesystemSourceResolver;
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

            public function resolve(string $path): ?array
            {
                if (! Storage::disk($this->fakeDisk)->exists($path)) {
                    return null;
                }

                return [
                    'source' => 'disk',
                    'disk' => $this->fakeDisk,
                    'mime_type' => $this->fakeMimeType,
                    'bytes' => Storage::disk($this->fakeDisk)->read($path),
                ];
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
