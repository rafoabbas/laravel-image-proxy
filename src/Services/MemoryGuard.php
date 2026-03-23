<?php

declare(strict_types=1);

namespace ImageProxy\Services;

class MemoryGuard
{
    public function check(string $bytes): void
    {
        $maxPixels = config('image-proxy.max_pixel_count', 25_000_000);
        $info = getimagesizefromstring($bytes);

        if ($info === false) {
            return;
        }

        [$width, $height] = $info;
        $pixels = $width * $height;

        abort_if(
            $pixels > $maxPixels,
            422,
            "Image dimensions ({$width}x{$height}) exceed the maximum allowed pixel count",
        );
    }
}
