<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Storage;
use ImageProxy\Contracts\ImageSourceResolverInterface;

class FilesystemSourceResolver implements ImageSourceResolverInterface
{
    /**
     * Resolve a path by checking if the file exists on the configured source disk.
     * The MIME type is detected from the file itself.
     *
     * @return array{disk: string, mime_type: string}|null
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

        return [
            'disk' => $disk,
            'mime_type' => $mimeType,
        ];
    }
}
