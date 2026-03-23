<?php

declare(strict_types=1);

namespace ImageProxy\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ImageProxy\Data\ImageRequestData;
use ImageProxy\Services\FilesystemSourceResolver;
use ImageProxy\Services\ImageCacheService;
use ImageProxy\Services\ImageResponseBuilder;
use ImageProxy\Services\ImageTransformer;
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
        private readonly FilesystemSourceResolver $filesystemResolver,
        private readonly UrlSourceResolver $urlResolver,
    ) {}

    public function __invoke(Request $request, string $path): Response
    {
        try {
            $params = ImageRequestData::fromRequest($request, $path);

            $cacheKey = $this->cache->buildCacheKey($params->path, $request->query());
            $cachePath = $this->cache->buildCachePath($cacheKey);
            $config = config('image-proxy');

            if ($this->cache->has($cachePath)) {
                return $this->response->respond(
                    $this->cache->get($cachePath), $cacheKey, $config['cache_max_age'],
                    $this->cache->lastModified($cachePath),
                );
            }

            $isUrl = str_starts_with($params->path, 'http://') || str_starts_with($params->path, 'https://');
            $source = $isUrl
                ? $this->urlResolver->resolve($params->path)
                : $this->filesystemResolver->resolve($params->path);

            abort_unless($source, 404, 'Image not found');
            abort_unless(in_array($source['mime_type'], $config['allowed_mime_types']), 415, 'Unsupported image type');

            $originalBytes = $source['bytes'];

            if (! $this->transformer->needsTransform($params->width, $params->height, $params->fit, $params->quality, $source['mime_type'])) {
                $this->cache->put($cachePath, $originalBytes);

                return $this->response->respond($originalBytes, $cacheKey, $config['cache_max_age']);
            }

            $bytes = $this->transformer->transform($originalBytes, $params->width, $params->height, $params->fit, $params->quality);
            $this->cache->put($cachePath, $bytes);

            return $this->response->respond($bytes, $cacheKey, $config['cache_max_age']);
        } catch (HttpException $e) {
            throw $e;
        } catch (DecoderException) {
            abort(422, 'Invalid or corrupted image file');
        } catch (FilesystemException) {
            abort(500, 'Storage error');
        } catch (Exception $e) {
            dd($e);
            abort(500, 'Image processing failed');
        }
    }
}
