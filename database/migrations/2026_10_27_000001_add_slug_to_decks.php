<?php

use App\Enums\DeckSegment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Deck URLs come from the title (/decks/the-classic-date-night-spark). Earlier slugs and the
 * deck code keep working as permanent redirects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('deck_code');
            $table->jsonb('previous_slugs')->nullable()->after('slug');
        });

        $reserved = array_map(fn (DeckSegment $s) => $s->slug(), DeckSegment::cases());
        $taken = [];
        foreach (DB::table('decks')->orderBy('deck_code')->get(['id', 'title', 'deck_code']) as $deck) {
            $base = Str::slug($deck->title) ?: Str::lower($deck->deck_code);
            if (in_array($base, $reserved, true)) {
                $base .= '-deck';
            }
            $slug = $base;
            for ($i = 2; isset($taken[$slug]); $i++) {
                $slug = "{$base}-{$i}";
            }
            $taken[$slug] = true;
            DB::table('decks')->where('id', $deck->id)->update(['slug' => $slug]);
        }

        Schema::table('decks', fn (Blueprint $table) => $table->string('slug')->nullable(false)->change());
    }

    public function down(): void
    {
        Schema::table('decks', fn (Blueprint $table) => $table->dropColumn(['slug', 'previous_slugs']));
    }
};
