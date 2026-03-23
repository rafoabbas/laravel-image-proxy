<?php

declare(strict_types=1);

namespace ImageProxy\Services;

class UrlSigner
{
    public function sign(string $path, array $params = []): string
    {
        $key = config('image-proxy.signing.key');
        ksort($params);

        $payload = $path . '?' . http_build_query($params);
        $signature = hash_hmac('sha256', $payload, (string) $key);

        $params['s'] = $signature;

        $routePath = config('image-proxy.route.path', 'img');

        return '/' . $routePath . '/' . $path . '?' . http_build_query($params);
    }

    public function verify(string $path, array $query): bool
    {
        $key = config('image-proxy.signing.key');
        $signature = $query['s'] ?? null;

        if (! $signature) {
            return false;
        }

        $params = $query;
        unset($params['s']);
        ksort($params);

        $payload = $path . '?' . http_build_query($params);
        $expected = hash_hmac('sha256', $payload, (string) $key);

        return hash_equals($expected, $signature);
    }
}
