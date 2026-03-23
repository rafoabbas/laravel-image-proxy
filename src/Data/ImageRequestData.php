<?php

declare(strict_types=1);

namespace ImageProxy\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ImageRequestData
{
    public const VALID_POSITIONS = [
        'top-left', 'top', 'top-right',
        'left', 'center', 'right',
        'bottom-left', 'bottom', 'bottom-right',
    ];

    public function __construct(
        public readonly string $path,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $fit,
        public readonly int $quality,
        public readonly bool $lqip = false,
        // Effects
        public readonly ?int $blur = null,
        public readonly ?int $sharpen = null,
        public readonly bool $grayscale = false,
        public readonly ?int $brightness = null,
        public readonly ?int $contrast = null,
        // Geometry
        public readonly ?int $rotate = null,
        public readonly ?string $flip = null,
        // Watermark
        public readonly ?string $watermark = null,
        public readonly string $watermarkPosition = 'bottom-right',
        public readonly int $watermarkAlpha = 50,
        public readonly int $watermarkSize = 25,
        public readonly int $watermarkPadding = 10,
        // Advanced crop
        public readonly ?float $focalX = null,
        public readonly ?float $focalY = null,
        // Visual extras
        public readonly ?int $borderRadius = null,
        public readonly ?int $padding = null,
        public readonly ?string $background = null,
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

        // Effects
        $blur = $request->has('blur') ? max(0, min($request->integer('blur'), $config['max_blur'] ?? 50)) : null;
        $sharpen = $request->has('sharpen') ? max(0, min($request->integer('sharpen'), 100)) : null;
        $grayscale = $request->boolean('grayscale');
        $brightness = $request->has('brightness') ? max(-100, min($request->integer('brightness'), 100)) : null;
        $contrast = $request->has('contrast') ? max(-100, min($request->integer('contrast'), 100)) : null;

        // Geometry
        $rotate = $request->has('rotate') ? ((int) $request->query('rotate')) % 360 : null;
        $flipValue = $request->query('flip');
        $flip = is_string($flipValue) && in_array($flipValue, ['h', 'v', 'both'], true) ? $flipValue : null;

        // Watermark
        $watermark = is_string($request->query('watermark')) ? $request->query('watermark') : null;

        if ($watermark !== null) {
            abort_if(Str::contains($watermark, ['..', "\0"]), 400, 'Invalid watermark path');
        }
        $watermarkPosition = is_string($request->query('watermark_position'))
            && in_array($request->query('watermark_position'), self::VALID_POSITIONS, true)
            ? $request->query('watermark_position')
            : 'bottom-right';
        $watermarkAlpha = $request->has('watermark_alpha') ? max(0, min($request->integer('watermark_alpha'), 100)) : 50;
        $watermarkSize = $request->has('watermark_size') ? max(1, min($request->integer('watermark_size'), 100)) : 25;
        $watermarkPadding = $request->has('watermark_padding') ? max(0, min($request->integer('watermark_padding'), 100)) : 10;

        // Advanced crop
        $focalX = $request->has('focal_x') ? max(0.0, min((float) $request->query('focal_x'), 1.0)) : null;
        $focalY = $request->has('focal_y') ? max(0.0, min((float) $request->query('focal_y'), 1.0)) : null;

        // Visual extras
        $borderRadius = $request->has('border_radius') ? max(0, min($request->integer('border_radius'), $config['max_border_radius'] ?? 1000)) : null;
        $padding = $request->has('padding') ? max(0, min($request->integer('padding'), $config['max_padding'] ?? 500)) : null;
        $background = self::sanitizeHex($request->query('bg'));

        return new self(
            path: $path,
            width: $w,
            height: $h,
            fit: $fit,
            quality: $q,
            lqip: $lqip,
            blur: $blur,
            sharpen: $sharpen,
            grayscale: $grayscale,
            brightness: $brightness,
            contrast: $contrast,
            rotate: $rotate,
            flip: $flip,
            watermark: $watermark,
            watermarkPosition: $watermarkPosition,
            watermarkAlpha: $watermarkAlpha,
            watermarkSize: $watermarkSize,
            watermarkPadding: $watermarkPadding,
            focalX: $focalX,
            focalY: $focalY,
            borderRadius: $borderRadius,
            padding: $padding,
            background: $background,
        );
    }

    private static function sanitizeHex(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = ltrim($value, '#');

        if (! preg_match('/^[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $value)) {
            return null;
        }

        return $value;
    }
}
