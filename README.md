# Laravel Image Proxy

On-the-fly image transformation, AVIF/WebP conversion, and caching proxy for Laravel.

## Features

- Automatic AVIF/WebP conversion with content negotiation (Accept header)
- Resize, crop, and quality control via query parameters
- LQIP (Low Quality Image Placeholder) support
- Signed URLs with HMAC-SHA256 for abuse prevention
- GD and Imagick driver support
- Disk-based caching with configurable storage backend
- Cache locking to prevent duplicate processing
- Memory guard to reject oversized images
- External URL proxying with domain allowlist and SSRF protection
- Remote disk (S3, R2, GCS) original caching to reduce repeated downloads
- `ImageProxy` facade and `@imageSrcset` Blade directive
- Artisan command for cache management (age-based, size-based cleanup)
- Supports Laravel 10, 11, 12, and 13

## Requirements

- PHP 8.2+
- GD extension (`ext-gd`) or Imagick extension (`ext-imagick`)
- Laravel 10.x / 11.x / 12.x / 13.x

## Installation

```bash
composer require rafoabbas/laravel-image-proxy
```

The package auto-discovers its service provider. Publish the config file:

```bash
php artisan vendor:publish --tag=image-proxy-config
```

## Configuration

After publishing, edit `config/image-proxy.php`:

```php
return [
    // Filesystem disk to read original images from
    'source_disk' => env('IMAGE_PROXY_SOURCE_DISK', 'public'),

    // Filesystem disk for storing processed/cached images
    'cache_disk' => env('IMAGE_PROXY_CACHE_DISK', 'local'),

    // Image processing driver: 'gd' or 'imagick'
    'driver' => env('IMAGE_PROXY_DRIVER', 'gd'),

    // Output format priority (first match by Accept header wins)
    'formats' => ['avif', 'webp'],

    // URL signing (HMAC-SHA256)
    'signing' => [
        'enabled' => env('IMAGE_PROXY_SIGNING_ENABLED', false),
        'key'     => env('IMAGE_PROXY_SIGNING_KEY'),
    ],

    // Cache remote originals locally
    'cache_remote_originals' => true,

    // Disks considered remote — originals are cached locally
    'remote_disks' => ['s3', 'r2', 'gcs'],

    // External domains allowed for URL proxying (empty = disabled)
    'allowed_domains' => [],

    // Route settings
    'route' => [
        'enabled'    => true,
        'path'       => 'img',
        'middleware'  => ['web'],
        'name'       => 'image-proxy',
    ],

    // Allowed source MIME types
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ],

    // Dimension and pixel limits
    'max_width'       => 4000,
    'max_height'      => 4000,
    'max_pixel_count' => 25_000_000,

    // Quality settings
    'min_quality'     => 40,
    'max_quality'     => 100,
    'default_quality' => 85,

    // Max source file size in bytes (default: 10MB)
    'max_file_size' => 10 * 1024 * 1024,

    // Cache-Control max-age in seconds (default: 1 year)
    'cache_max_age' => 31536000,
];
```

## Usage

### Basic

Once installed, images are served through the `/img/{path}` route:

```
/img/photos/landscape.jpg
```

This reads `photos/landscape.jpg` from the configured source disk, converts it to the best format supported by the client (AVIF > WebP), caches the result, and returns it with proper `Cache-Control`, `ETag`, `Vary`, and `Content-Type` headers.

### Query Parameters

| Parameter | Type   | Description                              |
|-----------|--------|------------------------------------------|
| `w`       | int    | Target width (clamped to `max_width`)    |
| `h`       | int    | Target height (clamped to `max_height`)  |
| `fit`     | string | Resize mode: `crop` for cover-fit        |
| `q`       | int    | Output quality (clamped between min/max) |
| `lqip`    | bool   | Return a 20px low-quality placeholder    |

**Examples:**

```
/img/photos/landscape.jpg?w=800
/img/photos/landscape.jpg?w=400&h=400&fit=crop
/img/photos/landscape.jpg?w=1200&q=60
/img/photos/landscape.jpg?lqip=1
```

### Generating URLs

#### Using the Facade

```php
use ImageProxy\Facades\ImageProxy;

// Simple URL
ImageProxy::url('photos/landscape.jpg', ['w' => 800]);

// Srcset string
ImageProxy::srcset('photos/landscape.jpg', [320, 640, 1024, 1920]);
```

When URL signing is enabled, all URLs generated through the facade are automatically signed.

#### Using the Blade Directive

```blade
@imageSrcset('photos/hero.jpg', [320, 640, 1024, 1920])

@imageSrcset('photos/hero.jpg', [320, 640], ['fit' => 'crop', 'h' => 400], '(max-width: 768px) 100vw, 50vw', 'Hero image')
```

This generates a complete `<img>` tag with `srcset`, `sizes`, `loading="lazy"`, and `decoding="async"` attributes.

#### Using Route Helper

```php
<img src="{{ route('image-proxy', ['path' => 'photos/landscape.jpg', 'w' => 800]) }}" alt="Landscape">
```

> **Note:** The route helper does not support signed URLs. Use the `ImageProxy` facade when signing is enabled.

### URL Signing

Enable signed URLs to prevent unauthorized transformations:

```env
IMAGE_PROXY_SIGNING_ENABLED=true
IMAGE_PROXY_SIGNING_KEY=your-secret-key-here
```

When enabled, all requests must include a valid `s` parameter. URLs without a valid signature receive a `403` response.

```php
// Generate a signed URL
ImageProxy::url('photos/secret.jpg', ['w' => 300]);
// => /img/photos/secret.jpg?w=300&s=a1b2c3...

// The signature covers the path and all parameters (tamper-proof)
```

### Content Negotiation (AVIF/WebP)

The proxy automatically selects the best output format based on the client's `Accept` header:

1. If the client supports AVIF and `'avif'` is in the `formats` config → serves AVIF
2. If the client supports WebP and `'webp'` is in the `formats` config → serves WebP
3. Falls back to the last format in the list

The response includes a `Vary: Accept` header for proper CDN caching.

> **Note:** AVIF encoding with the GD driver requires PHP 8.1+ with libavif. The Imagick driver provides more reliable AVIF support.

### LQIP (Low Quality Image Placeholder)

Request a tiny placeholder image for lazy loading:

```
/img/photos/hero.jpg?lqip=1
```

This returns a 20px wide, quality-20 thumbnail (typically under 1KB) that can be used as a blur-up placeholder.

### External URL Proxying

Add allowed domains to the config:

```php
'allowed_domains' => ['cdn.example.com', 'images.unsplash.com'],
```

Then pass the full URL as the path:

```
/img/https://cdn.example.com/photo.jpg?w=600
```

The proxy includes SSRF protection: localhost, private IPs, and reserved ranges are blocked.

### Remote Disk Caching

When using remote disks like S3 or R2 as the source, originals are automatically cached to the local cache disk. This avoids re-downloading the same file on subsequent requests with different transformation parameters.

Disable with `'cache_remote_originals' => false` in the config.

### Rate Limiting

The package does not include built-in rate limiting. Use Laravel's throttle middleware on the image proxy route:

```php
'route' => [
    'middleware' => ['web', 'throttle:60,1'],
],
```

Or apply more granular throttling with a custom rate limiter:

```php
// In AppServiceProvider::boot()
RateLimiter::for('images', function (Request $request) {
    return Limit::perMinute(100)->by($request->ip());
});

// In config/image-proxy.php
'route' => [
    'middleware' => ['web', 'throttle:images'],
],
```

## Cache Management

Clear the cache using the Artisan command:

```bash
# Clear everything
php artisan image-proxy:clear-cache --all

# Clear files older than 30 days
php artisan image-proxy:clear-cache --older-than=30

# Clear oldest files when cache exceeds 10GB
php artisan image-proxy:clear-cache --size-limit=10

# Also clear cached remote originals
php artisan image-proxy:clear-cache --all --originals
```

## Testing

```bash
composer test
```

## Code Style

```bash
composer lint       # Fix code style with Pint
composer lint:test  # Check code style
composer rector     # Apply Rector refactors
composer rector:dry # Dry-run Rector
```

## License

MIT
