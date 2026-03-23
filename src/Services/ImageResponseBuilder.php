<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Http\Response;

class ImageResponseBuilder
{
    public function respond(string $bytes, string $cacheKey, int $maxAge, ?int $lastModified = null): Response
    {
        $response = response($bytes)
            ->header('Content-Type', 'image/webp')
            ->header('Cache-Control', 'public, max-age=' . $maxAge . ', immutable')
            ->header('ETag', '"' . $cacheKey . '"');

        if ($lastModified) {
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }

        return $response;
    }
}
