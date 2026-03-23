<?php

declare(strict_types=1);

namespace ImageProxy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ImageProxy\Services\UrlSigner;

class VerifyImageSignature
{
    public function __construct(private readonly UrlSigner $signer) {}

    public function handle(Request $request, Closure $next)
    {
        if (! config('image-proxy.signing.enabled', false)) {
            return $next($request);
        }

        $path = $request->route('path');

        abort_unless(
            $this->signer->verify($path, $request->query()),
            403,
            'Invalid image signature',
        );

        return $next($request);
    }
}
