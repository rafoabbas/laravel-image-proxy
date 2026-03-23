<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Storage;
use ImageProxy\Contracts\ImageSourceResolverInterface;

class FilesystemSourceResolver implements ImageSourceResolverInterface
{
    /**
     * Resolve a path by checking if the file exists on the configured source disk.
     * The MIME type is detected from the file itself. Bytes are fetched (with remote disk caching).
     *
     * @return array{source: string, disk: string, mime_type: string, bytes: string}|null
     */
    public function resolve(string $path): ?array
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

        return [
            'source' => 'disk',
            'disk' => $disk,
            'mime_type' => $mimeType,
            'bytes' => $bytes,
        ];
    }

    private function fetchBytes(string $sourceDisk, string $path): string
    {
        $remoteDisksList = config('image-proxy.remote_disks', []);
        $cacheDisk = config('image-proxy.cache_disk');

        if (in_array($sourceDisk, $remoteDisksList)) {
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
