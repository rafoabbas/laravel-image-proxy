<?php

declare(strict_types=1);

namespace ImageProxy\Contracts;

use ImageProxy\Data\ImageSourceData;

interface ImageSourceResolverInterface
{
    public function resolve(string $path): ?ImageSourceData;
}
