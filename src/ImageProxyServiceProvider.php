<?php

declare(strict_types=1);

namespace ImageProxy;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ImageProxy\Console\Commands\ClearImageCacheCommand;
use ImageProxy\Contracts\ImageSourceResolverInterface;
use ImageProxy\Http\Controllers\ImageController;
use ImageProxy\Services\FilesystemSourceResolver;

class ImageProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/image-proxy.php', 'image-proxy');

        $this->app->bind(ImageSourceResolverInterface::class, function ($app): ImageSourceResolverInterface {
            return $app->make(FilesystemSourceResolver::class);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearImageCacheCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/image-proxy.php' => config_path('image-proxy.php'),
            ], 'image-proxy-config');
        }

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        if (! config('image-proxy.route.enabled', true)) {
            return;
        }

        $path = config('image-proxy.route.path', 'img');
        $middleware = config('image-proxy.route.middleware', ['web']);
        $name = config('image-proxy.route.name', 'image-proxy');

        Route::middleware($middleware)->group(function () use ($path, $name): void {
            Route::get($path . '/{path}', ImageController::class)
                ->where('path', '.*')
                ->name($name);
        });
    }
}
