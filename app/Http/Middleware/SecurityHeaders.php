<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for the API and the admin panel. The public site sets its own
 * (next.config.ts), including the Content-Security-Policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // JSON never needs framing; the admin may frame itself (Filament / Livewire previews).
        $headers->set('X-Frame-Options', $request->is('api/*') ? 'DENY' : 'SAMEORIGIN');
        if ($request->is('api/*') && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
