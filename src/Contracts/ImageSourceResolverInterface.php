<?php

declare(strict_types=1);

namespace ImageProxy\Contracts;

interface ImageSourceResolverInterface
{
    /**
     * Resolve a path to its source disk and MIME type.
     *
     * @return array{disk: string, mime_type: string}|null
     */
    public function resolve(string $path): ?array;
}
