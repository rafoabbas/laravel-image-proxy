<?php

declare(strict_types=1);

namespace ImageProxy\Data;

class ImageSourceData
{
    public function __construct(
        public readonly string $source,
        public readonly string $mimeType,
        public readonly string $bytes,
        public readonly ?string $disk = null,
    ) {}
}
