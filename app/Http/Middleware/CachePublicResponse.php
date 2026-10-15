<?php

namespace App\Http\Middleware;

use App\Support\ResponseCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonymous GETs of public catalogue endpoints are served from the cache (busted on every
 * content change, see FrontendCache::flush) and tell caches they may reuse them briefly.
 * Anything with credentials passes straight through, uncached and marked private.
 */
class CachePublicResponse
{
    public function handle(Request $request, Closure $next, string $ttl = '600'): Response
    {
        if (! $request->isMethod('GET') || $request->bearerToken() !== null) {
            return $next($request);
        }

        $url = $request->fullUrl();
        if ($hit = ResponseCache::get($url)) {
            return $this->public(response($hit['content'], $hit['status'], ['Content-Type' => $hit['type']]), 'HIT');
        }

        $response = $next($request);

        if ($response->getStatusCode() === 200) {
            ResponseCache::put($url, [
                'status' => 200,
                'content' => (string) $response->getContent(),
                'type' => (string) $response->headers->get('Content-Type', 'application/json'),
            ], (int) $ttl);
        }

        return $this->public($response, 'MISS');
    }

    private function public(Response $response, string $state): Response
    {
        $response->headers->set('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
        $response->headers->set('Vary', 'Authorization, Accept-Encoding');
        $response->headers->set('X-Response-Cache', $state);

        return $response;
    }
}
