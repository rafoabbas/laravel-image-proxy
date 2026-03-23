<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Storage;

class ImageCacheService
{
    private readonly string $disk;

    public function __construct()
    {
        $this->disk = config('image-proxy.cache_disk');
    }

    public function buildCacheKey(string $path, array $query, string $format = 'webp'): string
    {
        ksort($query);

        return sha1($path . '|' . http_build_query($query) . '|' . $format);
    }

    public function buildCachePath(string $cacheKey, string $format = 'webp'): string
    {
        $ext = $format === 'avif' ? 'avif' : 'webp';

        return substr($cacheKey, 0, 2) . '/' . $cacheKey . '.' . $ext;
    }

    public function has(string $cachePath): bool
    {
        return Storage::disk($this->disk)->exists($cachePath);
    }

    public function get(string $cachePath): string
    {
        return Storage::disk($this->disk)->read($cachePath);
    }

    public function put(string $cachePath, string $bytes): void
    {
        Storage::disk($this->disk)->put($cachePath, $bytes);
    }

    public function lastModified(string $cachePath): ?int
    {
        $fullPath = Storage::disk($this->disk)->path($cachePath);

        return file_exists($fullPath) ? filemtime($fullPath) : null;
    }
}
