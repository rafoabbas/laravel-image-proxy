<?php

declare(strict_types=1);

namespace ImageProxy\Tests;

use ImageProxy\ImageProxyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ImageProxyServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('image-proxy.cache_disk', 'image-proxy-cache');
        $app['config']->set('filesystems.disks.image-proxy-cache', [
            'driver' => 'local',
            'root' => storage_path('app/image-proxy-cache'),
        ]);
    }
}
