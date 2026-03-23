<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Storage;
use ImageProxy\Contracts\ImageSourceResolverInterface;
use ImageProxy\Data\ImageSourceData;

class FilesystemSourceResolver implements ImageSourceResolverInterface
{
    public function resolve(string $path): ?ImageSourceData
    {
        $disk = config('image-proxy.source_disk');

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $mimeType = Storage::disk($disk)->mimeType($path);

        if (! $mimeType) {
            return null;
        }

        $bytes = $this->fetchBytes($disk, $path);

        return new ImageSourceData(
            source: 'disk',
            mimeType: $mimeType,
            bytes: $bytes,
            disk: $disk,
        );
    }

    private function fetchBytes(string $sourceDisk, string $path): string
    {
        $remoteDisksList = config('image-proxy.remote_disks', []);
        $cacheRemote = config('image-proxy.cache_remote_originals', true);
        $cacheDisk = config('image-proxy.cache_disk');

        if ($cacheRemote && in_array($sourceDisk, $remoteDisksList)) {
            $originalCachePath = 'originals/' . $path;

            if (Storage::disk($cacheDisk)->exists($originalCachePath)) {
                return Storage::disk($cacheDisk)->read($originalCachePath);
            }

            abort_unless(Storage::disk($sourceDisk)->exists($path), 404, 'Source file not found');
            $originalBytes = Storage::disk($sourceDisk)->read($path);
            Storage::disk($cacheDisk)->put($originalCachePath, $originalBytes);

            return $originalBytes;
        }

        abort_unless(Storage::disk($sourceDisk)->exists($path), 404, 'Source file not found');

        return Storage::disk($sourceDisk)->read($path);
    }
}
