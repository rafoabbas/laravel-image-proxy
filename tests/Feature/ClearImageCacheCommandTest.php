<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('image-proxy-cache');
});

function seedCacheFiles(int $count = 3): void
{
    for ($i = 1; $i <= $count; $i++) {
        Storage::disk('image-proxy-cache')->put("ab/file{$i}.webp", str_repeat('x', 1024));
    }
}

test('shows error when no option is provided', function (): void {
    $this->artisan('image-proxy:clear-cache')
        ->expectsOutputToContain('Please provide an option')
        ->assertExitCode(1);
});

test('clears all cached images with --all', function (): void {
    seedCacheFiles(3);

    $this->artisan('image-proxy:clear-cache', ['--all' => true])
        ->expectsOutputToContain('Successfully cleared 3 cached images')
        ->assertExitCode(0);

    expect(Storage::disk('image-proxy-cache')->allFiles())->toBeEmpty();
});

test('clears originals when --all --originals is used', function (): void {
    seedCacheFiles(2);
    Storage::disk('image-proxy-cache')->put('originals/photo.jpg', 'original-bytes');
    Storage::disk('image-proxy-cache')->put('originals/url/abc123', 'url-bytes');

    $this->artisan('image-proxy:clear-cache', ['--all' => true, '--originals' => true])
        ->expectsOutputToContain('Successfully cleared')
        ->assertExitCode(0);
});

test('clears images older than specified days', function (): void {
    seedCacheFiles(2);

    // Touch files to be old
    $disk = Storage::disk('image-proxy-cache');
    $root = $disk->path('');

    foreach (['ab/file1.webp', 'ab/file2.webp'] as $file) {
        touch($root . '/' . $file, now()->subDays(60)->timestamp);
    }

    // Add a fresh file
    $disk->put('ab/fresh.webp', 'new-content');

    $this->artisan('image-proxy:clear-cache', ['--older-than' => 30])
        ->expectsOutputToContain('Cleared 2 old cached images')
        ->assertExitCode(0);

    $disk->assertExists('ab/fresh.webp');
    $disk->assertMissing('ab/file1.webp');
    $disk->assertMissing('ab/file2.webp');
});

test('clears oldest files when cache exceeds size limit', function (): void {
    $disk = Storage::disk('image-proxy-cache');
    $root = $disk->path('');

    // Create files totaling ~3KB
    $disk->put('ab/old.webp', str_repeat('x', 1024));
    touch($root . '/ab/old.webp', now()->subDays(10)->timestamp);

    $disk->put('ab/medium.webp', str_repeat('y', 1024));
    touch($root . '/ab/medium.webp', now()->subDays(5)->timestamp);

    $disk->put('ab/new.webp', str_repeat('z', 1024));

    // Set a limit just above 1KB (in GB) so oldest files get deleted
    $limitGB = 0.0000015; // ~1.5KB

    $this->artisan('image-proxy:clear-cache', ['--size-limit' => $limitGB])
        ->expectsOutputToContain('Deleted')
        ->assertExitCode(0);
});

test('does nothing when cache size is within limit', function (): void {
    seedCacheFiles(1);

    $this->artisan('image-proxy:clear-cache', ['--size-limit' => 1])
        ->expectsOutputToContain('Cache size is within limit')
        ->assertExitCode(0);

    Storage::disk('image-proxy-cache')->assertExists('ab/file1.webp');
});

test('clears all even when cache is already empty', function (): void {
    // No files seeded — cache disk exists but is empty
    $this->artisan('image-proxy:clear-cache', ['--all' => true])
        ->expectsOutputToContain('Successfully cleared 0 cached images')
        ->assertExitCode(0);
});
