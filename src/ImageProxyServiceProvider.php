<?php

declare(strict_types=1);

namespace ImageProxy;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ImageProxy\Console\Commands\ClearImageCacheCommand;
use ImageProxy\Http\Controllers\ImageController;
use ImageProxy\Http\Middleware\VerifyImageSignature;
use ImageProxy\Services\ImageCacheService;
use ImageProxy\Services\ImageProxyManager;
use ImageProxy\Services\ImageResponseBuilder;
use ImageProxy\Services\UrlSigner;

class ImageProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/image-proxy.php', 'image-proxy');

        $this->app->singleton(ImageCacheService::class);
        $this->app->singleton(ImageResponseBuilder::class);
        $this->app->singleton(UrlSigner::class);
        $this->app->singleton(ImageProxyManager::class);
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
        $this->registerBladeDirectives();
    }

    protected function registerRoutes(): void
    {
        if (! config('image-proxy.route.enabled', true)) {
            return;
        }

        $path = config('image-proxy.route.path', 'img');
        $middleware = config('image-proxy.route.middleware', ['web']);
        $name = config('image-proxy.route.name', 'image-proxy');

        Route::middleware(array_merge($middleware, [VerifyImageSignature::class]))
            ->group(function () use ($path, $name): void {
                Route::get($path . '/{path}', ImageController::class)
                    ->where('path', '.*')
                    ->name($name);
            });
    }

    protected function registerBladeDirectives(): void
    {
        Blade::directive('imageSrcset', fn (string $expression): string => "<?php echo app(\ImageProxy\Services\ImageProxyManager::class)->srcsetTag({$expression}); ?>");
    }
}
