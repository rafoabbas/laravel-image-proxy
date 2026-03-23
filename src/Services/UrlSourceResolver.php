<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ImageProxy\Contracts\ImageSourceResolverInterface;

class UrlSourceResolver implements ImageSourceResolverInterface
{
    /**
     * Resolve an external URL: validate domain, fetch bytes (with originals cache), detect MIME.
     *
     * @return array{source: string, mime_type: string, bytes: string}|null
     */
    public function resolve(string $path): ?array
    {
        $config = config('image-proxy');

        $host = strtolower((string) parse_url($path, PHP_URL_HOST));
        $allowedDomains = array_map(strtolower(...), $config['allowed_domains'] ?? []);

        abort_unless(in_array($host, $allowedDomains, true), 403, 'Domain not allowed');

        $bytes = $this->fetchFromUrl($path, $config['cache_disk']);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($bytes);

        return [
            'source' => 'url',
            'mime_type' => $mimeType,
            'bytes' => $bytes,
        ];
    }

    private function fetchFromUrl(string $url, string $cacheDisk): string
    {
        $cacheKey = 'originals/url/' . sha1($url);

        if (Storage::disk($cacheDisk)->exists($cacheKey)) {
            return Storage::disk($cacheDisk)->read($cacheKey);
        }

        $response = Http::timeout(10)->get($url);

        abort_unless($response->successful(), 502, 'Failed to fetch remote image');

        $bytes = $response->body();

        Storage::disk($cacheDisk)->put($cacheKey, $bytes);

        return $bytes;
    }
}
