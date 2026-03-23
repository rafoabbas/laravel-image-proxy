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
            if ($format === 'avif' && str_contains($accept, 'image/avif') && $this->supportsAvif()) {
                return 'avif';
            }

            if ($format === 'webp' && str_contains($accept, 'image/webp')) {
                return 'webp';
            }
        }

        return 'webp';
    }

    private function supportsAvif(): bool
    {
        $driver = config('image-proxy.driver', 'gd');

        if ($driver === 'imagick') {
            return extension_loaded('imagick') && in_array('AVIF', \Imagick::queryFormats('AVIF'));
        }

        return function_exists('imageavif');
    }

    public function mimeType(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            default => 'image/webp',
        };
    }
}
