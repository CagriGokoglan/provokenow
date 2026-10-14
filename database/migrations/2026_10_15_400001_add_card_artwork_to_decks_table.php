<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            // Absolute URL or public-disk path (see MediaUrl). SVGs are sanitised on save.
            $table->string('card_front_bg_url')->nullable();
            $table->string('card_back_bg_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn(['card_front_bg_url', 'card_back_bg_url']);
        });
    }
};
