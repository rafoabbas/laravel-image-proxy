<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ImageProxy\Contracts\ImageSourceResolverInterface;
use ImageProxy\Data\ImageSourceData;

class UrlSourceResolver implements ImageSourceResolverInterface
{
    public function resolve(string $path): ?ImageSourceData
    {
        $config = config('image-proxy');

        $host = strtolower((string) parse_url($path, PHP_URL_HOST));
        $allowedDomains = array_map(strtolower(...), $config['allowed_domains'] ?? []);

        abort_unless(in_array($host, $allowedDomains, true), 403, 'Domain not allowed');
        abort_if($this->isInternalHost($host), 403, 'Internal hosts are not allowed');

        $bytes = $this->fetchFromUrl($path, $config['cache_disk']);

        abort_if(
            strlen($bytes) > $config['max_file_size'],
            413,
            'Image exceeds maximum allowed file size',
        );

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($bytes);

        return new ImageSourceData(
            source: 'url',
            mimeType: $mimeType,
            bytes: $bytes,
        );
    }

    private function isInternalHost(string $host): bool
    {
        if (in_array($host, ['localhost', '0.0.0.0', '[::1]'], true)) {
            return true;
        }

        $ip = gethostbyname($host);

        if ($ip === $host) {
            return false;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
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
