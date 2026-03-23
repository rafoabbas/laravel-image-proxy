<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImageResponseBuilder
{
    public function respond(Request $request, string $bytes, string $cacheKey, int $maxAge, ?int $lastModified = null): Response
    {
        $etag = '"' . $cacheKey . '"';

        if ($this->isNotModified($request, $etag, $lastModified)) {
            return response('', 304)
                ->header('Cache-Control', 'public, max-age=' . $maxAge . ', immutable')
                ->header('ETag', $etag);
        }

        $response = response($bytes)
            ->header('Content-Type', 'image/webp')
            ->header('Cache-Control', 'public, max-age=' . $maxAge . ', immutable')
            ->header('ETag', $etag);

        if ($lastModified) {
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }

        return $response;
    }

    private function isNotModified(Request $request, string $etag, ?int $lastModified): bool
    {
        $ifNoneMatch = $request->header('If-None-Match');

        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return true;
        }

        $ifModifiedSince = $request->header('If-Modified-Since');

        if ($ifModifiedSince && $lastModified) {
            return $lastModified <= strtotime($ifModifiedSince);
        }

        return false;
    }
}
