<?php

declare(strict_types=1);

namespace ImageProxy\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ImageProxy\Data\ImageRequestData;
use ImageProxy\Services\FilesystemSourceResolver;
use ImageProxy\Services\ImageCacheService;
use ImageProxy\Services\ImageFormatNegotiator;
use ImageProxy\Services\ImageResponseBuilder;
use ImageProxy\Services\ImageTransformer;
use ImageProxy\Services\MemoryGuard;
use ImageProxy\Services\UrlSourceResolver;
use Intervention\Image\Exceptions\DecoderException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImageController
{
    public function __construct(
        private readonly ImageCacheService $cache,
        private readonly ImageTransformer $transformer,
        private readonly ImageResponseBuilder $response,
        private readonly ImageFormatNegotiator $formatNegotiator,
        private readonly MemoryGuard $memoryGuard,
        private readonly FilesystemSourceResolver $filesystemResolver,
        private readonly UrlSourceResolver $urlResolver,
    ) {}

    public function __invoke(Request $request, string $path): Response
    {
        try {
            $params = ImageRequestData::fromRequest($request, $path);
            $format = $this->formatNegotiator->negotiate($request);

            $cacheKey = $this->cache->buildCacheKey($params->path, $request->query(), $format);
            $cachePath = $this->cache->buildCachePath($cacheKey, $format);
            $config = config('image-proxy');

            if ($this->cache->has($cachePath)) {
                return $this->response->respond(
                    $request, $this->cache->get($cachePath), $cacheKey, $config['cache_max_age'],
                    $this->cache->lastModified($cachePath), $format,
                );
            }

            $lock = Cache::lock('img:' . $cacheKey, 10);

            try {
                $lock->block(10);

                if ($this->cache->has($cachePath)) {
                    return $this->response->respond(
                        $request, $this->cache->get($cachePath), $cacheKey, $config['cache_max_age'],
                        $this->cache->lastModified($cachePath), $format,
                    );
                }

                $isUrl = str_starts_with($params->path, 'http://') || str_starts_with($params->path, 'https://');
                $source = $isUrl
                    ? $this->urlResolver->resolve($params->path)
                    : $this->filesystemResolver->resolve($params->path);

                abort_unless($source, 404, 'Image not found');
                abort_unless(in_array($source->mimeType, $config['allowed_mime_types']), 415, 'Unsupported image type');
                abort_if(strlen($source->bytes) > $config['max_file_size'], 413, 'Image exceeds maximum allowed file size');

                $originalBytes = $source->bytes;
                $this->memoryGuard->check($originalBytes);

                if ($params->watermark !== null) {
                    $watermarkDisk = $config['watermark_disk'] ?? $config['source_disk'];
                    abort_unless(Storage::disk($watermarkDisk)->exists($params->watermark), 404, 'Watermark image not found');
                }

                if (! $this->transformer->needsTransform($params, $source->mimeType, $format)) {
                    $this->cache->put($cachePath, $originalBytes);

                    return $this->response->respond($request, $originalBytes, $cacheKey, $config['cache_max_age'], format: $format);
                }

                $bytes = $this->transformer->transform($params, $originalBytes, $format);
                $this->cache->put($cachePath, $bytes);

                return $this->response->respond($request, $bytes, $cacheKey, $config['cache_max_age'], format: $format);
            } finally {
                $lock->release();
            }
        } catch (HttpException $e) {
            throw $e;
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
    }
}
