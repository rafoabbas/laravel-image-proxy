<?php

declare(strict_types=1);

namespace ImageProxy\Services;

class ImageProxyManager
{
    public function __construct(private readonly UrlSigner $signer) {}

    public function url(string $path, array $params = []): string
    {
        if (config('image-proxy.signing.enabled', false)) {
            return $this->signer->sign($path, $params);
        }

        $routePath = config('image-proxy.route.path', 'img');
        $query = $params !== [] ? '?' . http_build_query($params) : '';

        return '/' . $routePath . '/' . $path . $query;
    }

    public function srcset(string $path, array $widths, array $params = []): string
    {
        $parts = [];

        foreach ($widths as $width) {
            $p = array_merge($params, ['w' => $width]);
            $url = $this->url($path, $p);
            $parts[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $parts);
    }

    public function srcsetTag(string $path, array $widths, array $params = [], string $sizes = '100vw', string $alt = ''): string
    {
        $srcset = $this->srcset($path, $widths, $params);
        $defaultUrl = $this->url($path, array_merge($params, ['w' => end($widths)]));
        $alt = e($alt);

        return sprintf(
            '<img src="%s" srcset="%s" sizes="%s" alt="%s" loading="lazy" decoding="async">',
            $defaultUrl,
            $srcset,
            $sizes,
            $alt,
        );
    }
}
