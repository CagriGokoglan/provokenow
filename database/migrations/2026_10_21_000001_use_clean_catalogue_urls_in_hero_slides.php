<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue listings moved to clean paths (/decks/taboo, /store/heels). Rewrite hero slide
 * links saved with the old query form; the web app also redirects any that remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite([
            '~^/decks\?level=([a-z0-9-]+)$~' => '/decks/$1',
            '~^/store\?category=([a-z0-9-]+)$~' => '/store/$1',
        ]);
    }

    public function down(): void
    {
        $this->rewrite([
            '~^/decks/(light-ignition|medium-spice|advanced|taboo)$~' => '/decks?level=$1',
        ]);
    }

    /** @param array<string, string> $patterns */
    private function rewrite(array $patterns): void
    {
        DB::table('hero_slides')->whereNotNull('custom_url')->orderBy('id')->each(function (object $slide) use ($patterns) {
            $url = preg_replace(array_keys($patterns), array_values($patterns), $slide->custom_url);
            if ($url !== $slide->custom_url) {
                DB::table('hero_slides')->where('id', $slide->id)->update(['custom_url' => $url]);
            }
        });
    }
};
