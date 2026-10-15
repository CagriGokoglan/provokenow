<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Tells the Next.js site to drop its cached API responses, so admin edits show up at once
 * instead of after the ISR window. Deferred under one name, so a request (or job, or artisan
 * command) that changes many rows sends a single ping after it finishes.
 */
final class FrontendCache
{
    public static function flush(): void
    {
        $url = config('provoke.frontend.revalidate_url');
        $secret = config('provoke.frontend.revalidate_secret');

        if (! $url || ! $secret) {
            return;
        }

        defer(function () use ($url, $secret) {
            try {
                Http::timeout(5)->withHeaders(['X-Revalidate-Secret' => $secret])->post($url)->throw();
            } catch (Throwable $e) {
                // The site still refreshes on its own schedule; never fail an admin save over this.
                report($e);
            }
        }, 'frontend-cache');
    }
}
