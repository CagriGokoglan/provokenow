<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cached bodies of the anonymous catalogue endpoints (see CachePublicResponse). Every key is
 * recorded so a content change can drop them all at once, on any cache store.
 */
final class ResponseCache
{
    private const REGISTRY = 'api-response:keys';

    public static function key(string $url): string
    {
        return 'api-response:'.sha1($url);
    }

    /** @return array{status: int, content: string, type: string}|null */
    public static function get(string $url): ?array
    {
        return Cache::get(self::key($url));
    }

    /** @param array{status: int, content: string, type: string} $response */
    public static function put(string $url, array $response, int $ttl): void
    {
        $key = self::key($url);
        Cache::put($key, $response, $ttl);

        $keys = Cache::get(self::REGISTRY, []);
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::forever(self::REGISTRY, $keys);
        }
    }

    public static function flush(): void
    {
        foreach (Cache::pull(self::REGISTRY, []) as $key) {
            Cache::forget($key);
        }
    }
}
