<?php

declare(strict_types=1);

namespace ImageProxy\Contracts;

interface ImageSourceResolverInterface
{
    /**
     * Resolve a path to its source metadata, MIME type, and bytes.
     *
     * @return array{source: string, mime_type: string, bytes: string, disk?: string}|null
     */
    public function resolve(string $path): ?array;
}
