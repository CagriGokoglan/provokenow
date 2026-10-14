<?php

namespace App\Console\Commands;

use App\Http\Controllers\SitemapController;
use App\Services\Seo\SitemapGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

#[Signature('provoke:sitemap {--path= : Write to this file instead of public/sitemap.xml}')]
#[Description('Regenerate sitemap.xml (static file + cached /sitemap.xml response)')]
class GenerateSitemap extends Command
{
    public function handle(SitemapGenerator $sitemap): int
    {
        $xml = $sitemap->generate();
        $path = $this->option('path') ?: public_path('sitemap.xml');

        File::put($path, $xml);
        Cache::put(SitemapController::CACHE_KEY, $xml, now()->addMinutes((int) config('provoke.seo.sitemap_cache_minutes')));

        $this->components->info(sprintf('Sitemap written to %s (%d URLs).', $path, substr_count($xml, '<url>')));

        return self::SUCCESS;
    }
}
