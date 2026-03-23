<?php

declare(strict_types=1);

namespace ImageProxy\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ImageRequestData
{
    public function __construct(
        public readonly string $path,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $fit,
        public readonly int $quality,
        public readonly bool $lqip = false,
    ) {}

    public static function fromRequest(Request $request, string $path): self
    {
        $path = ltrim($path, '/');
        abort_if(Str::contains($path, ['..', "\0"]), 400, 'Invalid path');

        $config = config('image-proxy');
        $lqip = $request->boolean('lqip');

        $w = $request->integer('w') ?: null;
        $h = $request->integer('h') ?: null;
        $fit = $request->query('fit');
        $q = $request->integer('q') ?: $config['default_quality'];

        if ($lqip) {
            $w = 20;
            $h = null;
            $q = 20;
        }

        $w = $w ? max(1, min($w, $config['max_width'])) : null;
        $h = $h ? max(1, min($h, $config['max_height'])) : null;
        $q = max($config['min_quality'], min($q, $config['max_quality']));

        return new self($path, $w, $h, $fit, $q, $lqip);
    }
}
