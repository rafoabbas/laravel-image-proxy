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
    | Quality Settings
    |--------------------------------------------------------------------------
    |
    | Control the WebP output quality.
    |
    */
    'min_quality' => 40,
    'max_quality' => 100,
    'default_quality' => 85,

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
