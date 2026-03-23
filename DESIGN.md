# Architecture Design: Laravel Image Proxy v2

## Current Architecture

```
Request → Route → ImageController
                    ├── ImageRequestData (validate/sanitize)
                    ├── ImageCacheService (check cache)
                    ├── FilesystemSourceResolver | UrlSourceResolver (fetch original)
                    ├── ImageTransformer (GD → WebP)
                    ├── ImageCacheService (store)
                    └── ImageResponseBuilder (ETag, 304, headers)
```

## Target Architecture

```
Request → Route → SignatureMiddleware (F2, optional)
                    ↓
               ImageController
                    ├── ImageRequestData (validate/sanitize + lqip + format)
                    ├── ImageFormatNegotiator (F1, Accept header → format)
                    ├── ImageCacheService (check cache, format-aware key)
                    │    └── Cache::lock() (R3, atomic)
                    ├── FilesystemSourceResolver | UrlSourceResolver → ImageSourceData DTO (R1)
                    ├── MemoryGuard check (R4)
                    ├── ImageTransformer (R5, GD|Imagick → WebP|AVIF)
                    ├── ImageCacheService (store)
                    └── ImageResponseBuilder (Content-Type per format)

Facade: ImageProxy::url() / ImageProxy::srcset() (F2, F4)
Blade:  @imageSrcset() (F4)
```

---

## Phase 1: Refactor Foundation

### R1 — ImageSourceData DTO

**New file:** `src/Data/ImageSourceData.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Data;

class ImageSourceData
{
    public function __construct(
        public readonly string $source,    // 'disk' | 'url'
        public readonly string $mimeType,
        public readonly string $bytes,
        public readonly ?string $disk = null,
    ) {}
}
```

**Changes:**
- `ImageSourceResolverInterface::resolve()` → return `?ImageSourceData` instead of `?array`
- `FilesystemSourceResolver::resolve()` → return `new ImageSourceData(...)`
- `UrlSourceResolver::resolve()` → return `new ImageSourceData(...)`
- `ImageController` → use `$source->mimeType`, `$source->bytes` instead of `$source['mime_type']`, `$source['bytes']`

---

### R2 — Exception Logging

**File:** `ImageController.php`

```php
// Before (line 69-74):
} catch (DecoderException) {
    abort(422, 'Invalid or corrupted image file');
} catch (FilesystemException) {
    abort(500, 'Storage error');
} catch (Exception) {
    abort(500, 'Image processing failed');
}

// After:
} catch (DecoderException $e) {
    Log::warning('Image proxy: invalid image', ['path' => $path, 'error' => $e->getMessage()]);
    abort(422, 'Invalid or corrupted image file');
} catch (FilesystemException $e) {
    Log::error('Image proxy: storage error', ['path' => $path, 'error' => $e->getMessage()]);
    abort(500, 'Storage error');
} catch (Exception $e) {
    Log::error('Image proxy: processing failed', ['path' => $path, 'error' => $e->getMessage()]);
    abort(500, 'Image processing failed');
}
```

---

### R5 — Imagick Driver Abstraction

**File:** `ImageTransformer.php`

```php
// Before:
use Intervention\Image\Drivers\Gd\Driver;

$manager = new ImageManager(new Driver);

// After:
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

$driver = match (config('image-proxy.driver', 'gd')) {
    'imagick' => new ImagickDriver(),
    default   => new GdDriver(),
};

$manager = new ImageManager($driver);
```

**Config addition:**
```php
'driver' => env('IMAGE_PROXY_DRIVER', 'gd'),  // 'gd' or 'imagick'
```

**composer.json change:**
```json
"require": {
    "ext-gd": "*"              // REMOVE from require
},
"suggest": {
    "ext-gd": "Required when using the GD driver (default)",
    "ext-imagick": "Required when using the Imagick driver (enables AVIF support)"
}
```

> **Note:** Intervention Image v3 already ships with both drivers. No extra dependency needed.

---

## Phase 2: New Features

### F1 — AVIF + Content Negotiation

**New file:** `src/Services/ImageFormatNegotiator.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Services;

use Illuminate\Http\Request;

class ImageFormatNegotiator
{
    /**
     * Determine the best output format based on Accept header and config.
     * Returns: 'avif', 'webp', or null (original format passthrough).
     */
    public function negotiate(Request $request): string
    {
        $accept = $request->header('Accept', '');
        $configFormats = config('image-proxy.formats', ['webp']);

        foreach ($configFormats as $format) {
            if ($format === 'avif' && str_contains($accept, 'image/avif')) {
                return 'avif';
            }
            if ($format === 'webp' && str_contains($accept, 'image/webp')) {
                return 'webp';
            }
        }

        // Fallback to last configured format
        return end($configFormats) ?: 'webp';
    }

    public function mimeType(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            default => 'image/webp',
        };
    }
}
```

**ImageTransformer changes:**

```php
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\WebpEncoder;

public function transform(string $bytes, ?int $width, ?int $height, ?string $fit, int $quality, string $format = 'webp'): string
{
    // ... resize logic unchanged ...

    $encoder = match ($format) {
        'avif' => new AvifEncoder(quality: $quality),
        default => new WebpEncoder(quality: $quality),
    };

    return $image->encode($encoder)->toString();
}

public function needsTransform(?int $width, ?int $height, ?string $fit, int $quality, string $mimeType, string $targetFormat = 'webp'): bool
{
    $targetMime = $targetFormat === 'avif' ? 'image/avif' : 'image/webp';

    return $width || $height || $quality !== config('image-proxy.default_quality') || $fit || $mimeType !== $targetMime;
}
```

**ImageCacheService changes:**

```php
public function buildCacheKey(string $path, array $query, string $format = 'webp'): string
{
    ksort($query);

    return sha1($path . '|' . http_build_query($query) . '|' . $format);
}

public function buildCachePath(string $cacheKey, string $format = 'webp'): string
{
    $ext = $format === 'avif' ? 'avif' : 'webp';

    return substr($cacheKey, 0, 2) . '/' . $cacheKey . '.' . $ext;
}
```

**ImageResponseBuilder changes:**

```php
public function respond(Request $request, string $bytes, string $cacheKey, int $maxAge, ?int $lastModified = null, string $format = 'webp'): Response
{
    // ...
    $contentType = $format === 'avif' ? 'image/avif' : 'image/webp';
    $response->header('Content-Type', $contentType);
    $response->header('Vary', 'Accept');  // Important for CDN caching
    // ...
}
```

**Config addition:**
```php
// Priority order: first match wins based on Accept header
'formats' => ['avif', 'webp'],
```

**Controller flow:**
```php
$format = $this->formatNegotiator->negotiate($request);
$cacheKey = $this->cache->buildCacheKey($params->path, $request->query(), $format);
$cachePath = $this->cache->buildCachePath($cacheKey, $format);
// ... rest uses $format throughout
```

---

### F2 — Signed URLs

**New file:** `src/Services/UrlSigner.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Services;

class UrlSigner
{
    public function sign(string $path, array $params = []): string
    {
        $key = config('image-proxy.signing.key');
        ksort($params);

        $payload = $path . '?' . http_build_query($params);
        $signature = hash_hmac('sha256', $payload, $key);

        $params['s'] = $signature;

        $routePath = config('image-proxy.route.path', 'img');

        return '/' . $routePath . '/' . $path . '?' . http_build_query($params);
    }

    public function verify(string $path, array $query): bool
    {
        $key = config('image-proxy.signing.key');
        $signature = $query['s'] ?? null;

        if (! $signature) {
            return false;
        }

        $params = $query;
        unset($params['s']);
        ksort($params);

        $payload = $path . '?' . http_build_query($params);
        $expected = hash_hmac('sha256', $payload, $key);

        return hash_equals($expected, $signature);
    }
}
```

**New file:** `src/Http/Middleware/VerifyImageSignature.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ImageProxy\Services\UrlSigner;

class VerifyImageSignature
{
    public function __construct(private readonly UrlSigner $signer) {}

    public function handle(Request $request, Closure $next)
    {
        if (! config('image-proxy.signing.enabled', false)) {
            return $next($request);
        }

        $path = $request->route('path');

        abort_unless(
            $this->signer->verify($path, $request->query()),
            403,
            'Invalid image signature',
        );

        return $next($request);
    }
}
```

**New file:** `src/Facades/ImageProxy.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Facades;

use Illuminate\Support\Facades\Facade;
use ImageProxy\Services\ImageProxyManager;

/**
 * @method static string url(string $path, array $params = [])
 * @method static string srcset(string $path, array $widths, array $params = [])
 *
 * @see \ImageProxy\Services\ImageProxyManager
 */
class ImageProxy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ImageProxyManager::class;
    }
}
```

**New file:** `src/Services/ImageProxyManager.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Services;

class ImageProxyManager
{
    public function __construct(private readonly UrlSigner $signer) {}

    /**
     * Generate a (signed) URL for an image.
     */
    public function url(string $path, array $params = []): string
    {
        if (config('image-proxy.signing.enabled', false)) {
            return $this->signer->sign($path, $params);
        }

        $routePath = config('image-proxy.route.path', 'img');
        $query = $params ? '?' . http_build_query($params) : '';

        return '/' . $routePath . '/' . $path . $query;
    }

    /**
     * Generate srcset attribute value for responsive images.
     */
    public function srcset(string $path, array $widths, array $params = []): string
    {
        $parts = [];

        foreach ($widths as $width) {
            $p = array_merge($params, ['w' => $width]);
            $url = $this->url($path, $p);
            $parts[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $parts);
    }
}
```

**Config addition:**
```php
'signing' => [
    'enabled' => env('IMAGE_PROXY_SIGNING_ENABLED', false),
    'key' => env('IMAGE_PROXY_SIGNING_KEY'),
],
```

**ServiceProvider registration:**
```php
$this->app->singleton(UrlSigner::class);
$this->app->singleton(ImageProxyManager::class);
```

**Route registration update:**
```php
Route::middleware(array_merge($middleware, [VerifyImageSignature::class]))
    ->group(function () use ($path, $name): void {
        Route::get($path . '/{path}', ImageController::class)
            ->where('path', '.*')
            ->name($name);
    });
```

---

### F3 — LQIP (Low Quality Image Placeholder)

**ImageRequestData changes:**

```php
public function __construct(
    public readonly string $path,
    public readonly ?int $width,
    public readonly ?int $height,
    public readonly ?string $fit,
    public readonly int $quality,
    public readonly bool $lqip = false,    // NEW
) {}

public static function fromRequest(Request $request, string $path): self
{
    // ... existing validation ...

    $lqip = $request->boolean('lqip');

    // LQIP overrides: tiny size, low quality
    if ($lqip) {
        $w = 20;
        $h = null;
        $q = 20;
    }

    return new self($path, $w, $h, $fit, $q, $lqip);
}
```

**ImageCacheService changes:**
The cache key already includes query params (`lqip=1` will be part of it), so cache paths are automatically separate. No change needed.

**Controller logic:**
LQIP requests always need transform (tiny + low quality), so `needsTransform()` will return true. No special controller logic needed — the existing flow handles it naturally.

---

### F4 — Srcset Blade Helper

**ServiceProvider addition:**

```php
use Illuminate\Support\Facades\Blade;

public function boot(): void
{
    // ... existing boot code ...

    Blade::directive('imageSrcset', function (string $expression) {
        return "<?php echo app(\ImageProxy\Services\ImageProxyManager::class)->srcsetTag({$expression}); ?>";
    });
}
```

**ImageProxyManager addition:**

```php
/**
 * Generate a full <img> tag with srcset and sizes attributes.
 *
 * @param  string  $path    Image path
 * @param  array   $widths  [320, 640, 1024, 1920]
 * @param  array   $params  Extra params like ['q' => 80, 'fit' => 'crop', 'h' => 400]
 * @param  string  $sizes   Sizes attribute (default: 100vw)
 * @param  string  $alt     Alt text
 */
public function srcsetTag(string $path, array $widths, array $params = [], string $sizes = '100vw', string $alt = ''): string
{
    $srcset = $this->srcset($path, $widths, $params);
    $defaultUrl = $this->url($path, array_merge($params, ['w' => end($widths)]));
    $alt = e($alt);

    return sprintf(
        '<img src="%s" srcset="%s" sizes="%s" alt="%s" loading="lazy" decoding="async">',
        $defaultUrl, $srcset, $sizes, $alt,
    );
}
```

**Blade usage:**
```blade
@imageSrcset('photos/hero.jpg', [320, 640, 1024, 1920])
@imageSrcset('photos/hero.jpg', [320, 640], ['fit' => 'crop', 'h' => 400], '(max-width: 768px) 100vw, 50vw', 'Hero image')
```

---

## Phase 3: Stability & Guards

### R3 — Cache Lock (Race Condition Prevention)

**ImageController changes:**

```php
use Illuminate\Support\Facades\Cache;

// Inside __invoke, after cache miss detected:
$lock = Cache::lock('img:' . $cacheKey, 10); // 10 second timeout

if (! $lock->get()) {
    // Another process is generating this image, wait then serve from cache
    $lock->block(10);

    if ($this->cache->has($cachePath)) {
        return $this->response->respond(
            $request, $this->cache->get($cachePath), $cacheKey, $config['cache_max_age'],
            $this->cache->lastModified($cachePath), $format,
        );
    }
}

try {
    // ... process image ...
} finally {
    $lock->release();
}
```

> Uses Laravel's atomic lock. Works with file, redis, database cache drivers.

---

### R4 — Memory Guard

**New file:** `src/Services/MemoryGuard.php`

```php
<?php

declare(strict_types=1);

namespace ImageProxy\Services;

class MemoryGuard
{
    /**
     * Check if image dimensions are within safe processing limits.
     * GD uses ~5 bytes per pixel (RGBA + overhead). Imagick is similar.
     */
    public function check(string $bytes): void
    {
        $maxPixels = config('image-proxy.max_pixel_count', 25_000_000);
        $info = getimagesizefromstring($bytes);

        if ($info === false) {
            return; // Let the transformer handle invalid images
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
```

**Controller integration:**
```php
// After source resolution, before transform:
$this->memoryGuard->check($source->bytes);
```

**Config addition:**
```php
// ~25 million pixels ≈ 5700x4400 ≈ ~120MB GD memory
'max_pixel_count' => 25_000_000,
```

---

### R6 — Remote Disk Caching Config

**Config change:**
```php
// Before:
'remote_disks' => ['s3', 'r2', 'gcs'],

// After (backward compatible):
'cache_remote_originals' => true,   // Master switch
'remote_disks' => ['s3', 'r2', 'gcs'],  // Kept as-is
```

**FilesystemSourceResolver change:**
```php
private function fetchBytes(string $sourceDisk, string $path): string
{
    $remoteDisksList = config('image-proxy.remote_disks', []);
    $cacheRemote = config('image-proxy.cache_remote_originals', true);

    if ($cacheRemote && in_array($sourceDisk, $remoteDisksList)) {
        // ... existing remote caching logic ...
    }

    // ... direct read ...
}
```

---

## New File Tree (additions marked with +)

```
src/
├── Console/Commands/
│   └── ClearImageCacheCommand.php
├── Contracts/
│   └── ImageSourceResolverInterface.php     (modified: returns DTO)
├── Data/
│   ├── ImageRequestData.php                 (modified: +lqip field)
│   └── ImageSourceData.php                  (+NEW: DTO)
├── Facades/
│   └── ImageProxy.php                       (+NEW: Facade)
├── Http/
│   ├── Controllers/
│   │   └── ImageController.php              (modified: logging, lock, format, guard)
│   └── Middleware/
│       └── VerifyImageSignature.php         (+NEW: Signed URL middleware)
├── Services/
│   ├── FilesystemSourceResolver.php         (modified: returns DTO, config check)
│   ├── ImageCacheService.php                (modified: format-aware paths)
│   ├── ImageFormatNegotiator.php            (+NEW: Accept header negotiation)
│   ├── ImageProxyManager.php                (+NEW: url(), srcset(), srcsetTag())
│   ├── ImageResponseBuilder.php             (modified: dynamic Content-Type, Vary)
│   ├── ImageTransformer.php                 (modified: driver choice, AVIF encoder)
│   ├── MemoryGuard.php                      (+NEW: pixel count check)
│   └── UrlSigner.php                        (+NEW: HMAC signing/verification)
└── ImageProxyServiceProvider.php            (modified: new registrations, Blade directive)
```

**New files: 6** | **Modified files: 8**

---

## Config Final Shape

```php
return [
    'source_disk'             => env('IMAGE_PROXY_SOURCE_DISK', 'public'),
    'cache_disk'              => env('IMAGE_PROXY_CACHE_DISK', 'local'),
    'cache_remote_originals'  => true,                          // NEW (R6)
    'remote_disks'            => ['s3', 'r2', 'gcs'],
    'allowed_domains'         => [],

    'driver'                  => env('IMAGE_PROXY_DRIVER', 'gd'),  // NEW (R5)

    'formats'                 => ['avif', 'webp'],              // NEW (F1)

    'signing' => [                                               // NEW (F2)
        'enabled' => env('IMAGE_PROXY_SIGNING_ENABLED', false),
        'key'     => env('IMAGE_PROXY_SIGNING_KEY'),
    ],

    'route' => [
        'enabled'    => true,
        'path'       => 'img',
        'middleware'  => ['web'],
        'name'       => 'image-proxy',
    ],

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',                                           // NEW (F1)
    ],

    'max_width'          => 4000,
    'max_height'         => 4000,
    'max_pixel_count'    => 25_000_000,                         // NEW (R4)
    'min_quality'        => 40,
    'max_quality'        => 100,
    'default_quality'    => 85,
    'max_file_size'      => 10 * 1024 * 1024,
    'cache_max_age'      => 31536000,
];
```

---

## Implementation Order

```
Phase 1 (Refactor):  R1 → R2 → R5           ~3 commits
Phase 2 (Features):  F1 → F2 → F3 → F4      ~4 commits
Phase 3 (Stability): R3 → R4 → R6           ~3 commits
Phase 4 (Tests):     R7                       ~1-2 commits
Phase 5 (Docs):      README update            ~1 commit
```

Each phase builds on the previous. Phase 1 must complete first as F1 depends on R5 (Imagick for AVIF) and F2/F3/F4 depend on R1 (DTO).

---

## Breaking Changes

**None.** All new features are opt-in via config:
- `driver` defaults to `'gd'` (existing behavior)
- `formats` defaults to `['webp']` if not set
- `signing.enabled` defaults to `false`
- `lqip` query param only activates when passed
- `cache_remote_originals` defaults to `true` (existing behavior)
- `max_pixel_count` defaults to 25M (generous, won't break existing usage)

---

## Facade Alias (optional)

Users can add to `config/app.php`:
```php
'aliases' => [
    'ImageProxy' => \ImageProxy\Facades\ImageProxy::class,
],
```

Or use auto-discovery via `composer.json`:
```json
"extra": {
    "laravel": {
        "providers": ["ImageProxy\\ImageProxyServiceProvider"],
        "aliases": {
            "ImageProxy": "ImageProxy\\Facades\\ImageProxy"
        }
    }
}
```
