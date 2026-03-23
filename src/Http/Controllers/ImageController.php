<?php

declare(strict_types=1);

namespace ImageProxy\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ImageProxy\Contracts\ImageSourceResolverInterface;
use ImageProxy\Services\ImageTransformer;
use Intervention\Image\Exceptions\DecoderException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImageController
{
    public function __construct(
        private ImageSourceResolverInterface $sourceResolver,
        private ImageTransformer $transformer,
    ) {}

    public function __invoke(Request $request, string $path): Response
    {
        try {
            $path = ltrim($path, '/');
            abort_if(Str::contains($path, ['..', "\0"]), 400, 'Invalid path');

            $config = config('image-proxy');

            $w = $request->integer('w') ?: null;
            $h = $request->integer('h') ?: null;
            $fit = $request->query('fit');
            $q = $request->integer('q') ?: $config['default_quality'];

            $w = $w ? max(1, min($w, $config['max_width'])) : null;
            $h = $h ? max(1, min($h, $config['max_height'])) : null;
            $q = max($config['min_quality'], min($q, $config['max_quality']));

            $query = $request->query();
            ksort($query);
            $cacheKey = sha1($path . '|' . http_build_query($query));

            $cacheDisk = $config['cache_disk'];
            $cachePath = substr($cacheKey, 0, 2) . '/' . $cacheKey . '.webp';

            if (Storage::disk($cacheDisk)->exists($cachePath)) {
                return $this->respondFromCache($cacheDisk, $cachePath, $cacheKey, $config['cache_max_age']);
            }

            $source = $this->sourceResolver->resolve($path);
            abort_unless($source, 404, 'Image not found');

            abort_unless(
                in_array($source['mime_type'], $config['allowed_mime_types']),
                415,
                'Unsupported image type',
            );

            $sourceDisk = $source['disk'];
            $remoteDisksList = $config['remote_disks'] ?? [];
            $originalBytes = $this->fetchOriginalBytes($sourceDisk, $path, $cacheDisk, $remoteDisksList);

            if (! $this->transformer->needsTransform($w, $h, $fit, $q, $source['mime_type'])) {
                Storage::disk($cacheDisk)->put($cachePath, $originalBytes);

                return $this->respond($originalBytes, $cacheKey, $config['cache_max_age']);
            }

            $bytes = $this->transformer->transform($originalBytes, $w, $h, $fit, $q);

            Storage::disk($cacheDisk)->put($cachePath, $bytes);

            return $this->respond($bytes, $cacheKey, $config['cache_max_age']);
        } catch (HttpException $e) {
            throw $e;
        } catch (DecoderException) {
            abort(422, 'Invalid or corrupted image file');
        } catch (FilesystemException) {
            abort(500, 'Storage error');
        } catch (Exception) {
            abort(500, 'Image processing failed');
        }
    }

    private function fetchOriginalBytes(string $sourceDisk, string $path, string $cacheDisk, array $remoteDisksList): string
    {
        if (in_array($sourceDisk, $remoteDisksList)) {
            $originalCachePath = 'originals/' . $path;

            if (Storage::disk($cacheDisk)->exists($originalCachePath)) {
                return Storage::disk($cacheDisk)->read($originalCachePath);
            }

            abort_unless(Storage::disk($sourceDisk)->exists($path), 404, 'Source file not found');
            $originalBytes = Storage::disk($sourceDisk)->read($path);
            Storage::disk($cacheDisk)->put($originalCachePath, $originalBytes);

            return $originalBytes;
        }

        abort_unless(Storage::disk($sourceDisk)->exists($path), 404, 'Source file not found');

        return Storage::disk($sourceDisk)->read($path);
    }

    private function respondFromCache(string $cacheDisk, string $cachePath, string $cacheKey, int $maxAge): Response
    {
        $cachedFile = Storage::disk($cacheDisk)->path($cachePath);
        $lastModified = filemtime($cachedFile);

        return $this->respond(
            Storage::disk($cacheDisk)->read($cachePath),
            $cacheKey,
            $maxAge,
            $lastModified,
        );
    }

    private function respond(string $bytes, string $cacheKey, int $maxAge, ?int $lastModified = null): Response
    {
        $response = response($bytes)
            ->header('Content-Type', 'image/webp')
            ->header('Cache-Control', 'public, max-age=' . $maxAge . ', immutable')
            ->header('ETag', '"' . $cacheKey . '"');

        if ($lastModified) {
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }

        return $response;
    }
}
