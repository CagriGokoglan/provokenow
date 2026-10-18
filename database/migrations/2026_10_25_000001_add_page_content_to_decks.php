<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deck page sections written per deck in the admin (empty = generated text). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->text('whats_inside')->nullable()->after('core_theme_keywords');
            $table->text('how_to_play')->nullable()->after('whats_inside');
            $table->text('who_its_for')->nullable()->after('how_to_play');
        });
    }

    public function down(): void
    {
        Schema::table('decks', fn (Blueprint $table) => $table->dropColumn(['whats_inside', 'how_to_play', 'who_its_for']));
    }
};
