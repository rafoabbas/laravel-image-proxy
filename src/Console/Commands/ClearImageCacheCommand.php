<?php

declare(strict_types=1);

namespace ImageProxy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ClearImageCacheCommand extends Command
{
    protected $signature = 'image-proxy:clear-cache
                            {--all : Clear all cached images}
                            {--older-than= : Clear images older than X days (e.g., 30)}
                            {--size-limit= : Clear cache if total size exceeds limit in GB (e.g., 10)}
                            {--originals : Also clear remote original file cache}';

    protected $description = 'Clear the image proxy cache based on age or size limits';

    public function handle(): int
    {
        $cacheDisk = Storage::disk(config('image-proxy.cache_disk'));
        $cacheRoot = $cacheDisk->path('');

        if (! File::exists($cacheRoot)) {
            $this->info('Cache directory does not exist.');

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            return $this->clearAll($cacheRoot);
        }

        if ($olderThan = $this->option('older-than')) {
            return $this->clearOlderThan($cacheRoot, (int) $olderThan);
        }

        if ($sizeLimit = $this->option('size-limit')) {
            return $this->clearBySize($cacheRoot, (float) $sizeLimit);
        }

        $this->error('Please provide an option: --all, --older-than, or --size-limit');

        return self::FAILURE;
    }

    private function clearAll(string $cacheRoot): int
    {
        $this->info('Clearing all image cache...');

        $processedCount = $this->deleteFiles($cacheRoot);

        if ($this->option('originals')) {
            $originalPath = $cacheRoot . '/originals';

            if (File::exists($originalPath)) {
                $originalCount = $this->deleteFiles($originalPath);
                $this->info("Cleared {$originalCount} remote original files");
            }
        }

        $this->info("Successfully cleared {$processedCount} cached images");

        return self::SUCCESS;
    }

    private function clearOlderThan(string $cacheRoot, int $days): int
    {
        $this->info("Clearing images older than {$days} days...");

        $cutoffTime = now()->subDays($days)->timestamp;
        $deletedCount = 0;

        foreach (File::allFiles($cacheRoot) as $file) {
            if ($file->getMTime() < $cutoffTime) {
                File::delete($file->getPathname());
                $deletedCount++;
            }
        }

        $this->info("Cleared {$deletedCount} old cached images");

        return self::SUCCESS;
    }

    private function clearBySize(string $cacheRoot, float $limitGB): int
    {
        $this->info("Checking cache size against limit of {$limitGB}GB...");

        $totalSize = $this->getDirectorySize($cacheRoot);
        $totalSizeGB = $totalSize / 1024 / 1024 / 1024;

        $this->info('Current cache size: ' . number_format($totalSizeGB, 2) . 'GB');

        if ($totalSizeGB <= $limitGB) {
            $this->info('Cache size is within limit. No action needed.');

            return self::SUCCESS;
        }

        $files = collect(File::allFiles($cacheRoot))
            ->sortBy(fn ($file) => $file->getMTime());

        $deletedCount = 0;
        $deletedSize = 0;
        $currentSizeGB = $totalSizeGB;

        foreach ($files as $file) {
            $fileSize = $file->getSize();
            File::delete($file->getPathname());
            $deletedSize += $fileSize;
            $deletedCount++;

            $currentSizeGB = ($totalSize - $deletedSize) / 1024 / 1024 / 1024;

            if ($currentSizeGB <= $limitGB) {
                break;
            }
        }

        $this->info("Deleted {$deletedCount} files (" . number_format($deletedSize / 1024 / 1024, 2) . 'MB)');
        $this->info('New cache size: ' . number_format($currentSizeGB, 2) . 'GB');

        return self::SUCCESS;
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;

        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function deleteFiles(string $path): int
    {
        $count = 0;

        foreach (File::allFiles($path) as $file) {
            File::delete($file->getPathname());
            $count++;
        }

        return $count;
    }
}
