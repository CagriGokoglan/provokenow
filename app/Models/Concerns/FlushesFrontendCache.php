<?php

namespace App\Models\Concerns;

use App\Support\FrontendCache;

/** Content shown on the public site: any change refreshes the web app's cache. */
trait FlushesFrontendCache
{
    public static function bootFlushesFrontendCache(): void
    {
        static::saved(fn () => FrontendCache::flush());
        static::deleted(fn () => FrontendCache::flush());
    }
}
