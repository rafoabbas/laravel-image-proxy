# Laravel Image Proxy

On-the-fly image transformation, WebP conversion, and caching proxy for Laravel.

## Features

- Automatic WebP conversion for JPEG, PNG, GIF, and WebP sources
- Resize, crop, and quality control via query parameters
- Disk-based caching with configurable storage backend
- External URL proxying with domain allowlist
- Remote disk (S3, R2, GCS) original caching to reduce repeated downloads
- Artisan command for cache management (age-based, size-based cleanup)
- Supports Laravel 10, 11, 12, and 13

## Requirements

- PHP 8.2+
- GD extension (`ext-gd`)
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
    ],

    // Dimension limits
    'max_width'  => 4000,
    'max_height' => 4000,

    // Quality settings
    'min_quality'     => 40,
    'max_quality'     => 100,
    'default_quality' => 85,

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

This reads `photos/landscape.jpg` from the configured source disk, converts it to WebP, caches the result, and returns it with proper `Cache-Control`, `ETag`, and `Content-Type` headers.

### Query Parameters

| Parameter | Type   | Description                              |
|-----------|--------|------------------------------------------|
| `w`       | int    | Target width (clamped to `max_width`)    |
| `h`       | int    | Target height (clamped to `max_height`)  |
| `fit`     | string | Resize mode: `crop` for cover-fit        |
| `q`       | int    | WebP quality (clamped between min/max)   |

**Examples:**

```
/img/photos/landscape.jpg?w=800
/img/photos/landscape.jpg?w=400&h=400&fit=crop
/img/photos/landscape.jpg?w=1200&q=60
```

### Generating URLs in Blade

```php
<img src="{{ route('image-proxy', ['path' => 'photos/landscape.jpg', 'w' => 800]) }}" alt="Landscape">
```

### External URL Proxying

Add allowed domains to the config:

```php
'allowed_domains' => ['cdn.example.com', 'images.unsplash.com'],
```

Then pass the full URL as the path:

```
/img/https://cdn.example.com/photo.jpg?w=600
```

### Remote Disk Caching

When using remote disks like S3 or R2 as the source, originals are automatically cached to the local cache disk. This avoids re-downloading the same file on subsequent requests with different transformation parameters.

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
