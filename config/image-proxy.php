<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source Disk
    |--------------------------------------------------------------------------
    |
    | The default filesystem disk to read original images from.
    | This can be any disk configured in your filesystems.php
    | (e.g., 'public', 'local', 's3', 'r2', etc.)
    |
    */
    'source_disk' => env('IMAGE_PROXY_SOURCE_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Cache Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used for storing processed/cached images.
    | You should create a dedicated disk for this in filesystems.php.
    |
    */
    'cache_disk' => env('IMAGE_PROXY_CACHE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    |
    | The image processing driver to use. Supported: "gd", "imagick".
    | GD is available by default in most PHP installations.
    | Imagick provides better quality and AVIF support.
    |
    */
    'driver' => env('IMAGE_PROXY_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Output Formats
    |--------------------------------------------------------------------------
    |
    | Priority-ordered list of output formats. The first format supported
    | by the client's Accept header will be used. Supported: "avif", "webp".
    | AVIF requires Imagick with libavif, or GD with PHP 8.1+ and libavif.
    | The negotiator automatically falls back to WebP if AVIF is unavailable.
    |
    */
    'formats' => ['webp'],

    /*
    |--------------------------------------------------------------------------
    | URL Signing
    |--------------------------------------------------------------------------
    |
    | When enabled, all image URLs must include a valid HMAC-SHA256 signature.
    | This prevents abuse by ensuring only your application can generate
    | valid image URLs. Use ImageProxy::url() to generate signed URLs.
    |
    */
    'signing' => [
        'enabled' => env('IMAGE_PROXY_SIGNING_ENABLED', false),
        'key' => env('IMAGE_PROXY_SIGNING_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Remote Originals
    |--------------------------------------------------------------------------
    |
    | When enabled, original files fetched from remote disks (S3, R2, GCS)
    | will be cached locally to avoid repeated downloads.
    |
    */
    'cache_remote_originals' => true,

    /*
    |--------------------------------------------------------------------------
    | Remote Disks
    |--------------------------------------------------------------------------
    |
    | Disks that are considered "remote" (e.g., S3, R2, GCS).
    | Original files from remote disks will be cached locally
    | to avoid repeated downloads on subsequent transformations.
    |
    */
    'remote_disks' => ['s3', 'r2', 'gcs'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Domains
    |--------------------------------------------------------------------------
    |
    | List of external domains from which images can be fetched.
    | Example: ['example.com', 'cdn.example.com']
    | Leave empty to disable external URL fetching.
    |
    */
    'allowed_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the route path prefix and middleware for serving images.
    |
    */
    'route' => [
        'enabled' => true,
        'path' => 'img',
        'middleware' => ['web'],
        'name' => 'image-proxy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | Only images with these MIME types will be processed.
    |
    */
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dimension Limits
    |--------------------------------------------------------------------------
    |
    | Maximum allowed width and height for image transformations.
    |
    */
    'max_width' => 4000,
    'max_height' => 4000,

    /*
    |--------------------------------------------------------------------------
    | Maximum Pixel Count
    |--------------------------------------------------------------------------
    |
    | Maximum total pixel count (width x height) allowed for processing.
    | This prevents memory exhaustion from extremely large images.
    | Default: 25 million pixels (~5700x4400, ~120MB GD memory).
    |
    */
    'max_pixel_count' => 25_000_000,

    /*
    |--------------------------------------------------------------------------
    | Quality Settings
    |--------------------------------------------------------------------------
    |
    | Control the output quality for WebP and AVIF encoding.
    |
    */
    'min_quality' => 40,
    'max_quality' => 100,
    'default_quality' => 85,

    /*
    |--------------------------------------------------------------------------
    | Max File Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed file size in bytes for source images.
    | Images exceeding this limit will be rejected with a 413 response.
    | Default: 10MB (10 * 1024 * 1024)
    |
    */
    'max_file_size' => 10 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Cache Max Age
    |--------------------------------------------------------------------------
    |
    | The Cache-Control max-age value in seconds (default: 1 year).
    |
    */
    'cache_max_age' => 31536000,

];
