<?php

namespace App\Http\Controllers;

use App\Services\Seo\SitemapGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public const CACHE_KEY = 'seo:sitemap.xml';

    public function __invoke(SitemapGenerator $sitemap): Response
    {
        $xml = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes((int) config('provoke.seo.sitemap_cache_minutes')),
            fn () => $sitemap->generate(),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
