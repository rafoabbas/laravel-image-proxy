<?php

declare(strict_types=1);

namespace ImageProxy\Facades;

use Illuminate\Support\Facades\Facade;
use ImageProxy\Services\ImageProxyManager;

/**
 * @method static string url(string $path, array $params = [])
 * @method static string srcset(string $path, array $widths, array $params = [])
 * @method static string srcsetTag(string $path, array $widths, array $params = [], string $sizes = '100vw', string $alt = '')
 *
 * @see ImageProxyManager
 */
class ImageProxy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ImageProxyManager::class;
    }
}
