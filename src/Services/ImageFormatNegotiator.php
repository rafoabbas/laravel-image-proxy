<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Http\Request;

class ImageFormatNegotiator
{
    public function negotiate(Request $request): string
    {
        $accept = $request->header('Accept', '');
        $formats = config('image-proxy.formats', ['webp']);

        foreach ($formats as $format) {
            if ($format === 'avif' && str_contains($accept, 'image/avif')) {
                return 'avif';
            }

            if ($format === 'webp' && str_contains($accept, 'image/webp')) {
                return 'webp';
            }
        }

        return end($formats) ?: 'webp';
    }

    public function mimeType(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            default => 'image/webp',
        };
    }
}
