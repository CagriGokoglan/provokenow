<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-chosen public preview / sample cards (at most two per deck).
        Schema::table('cards', function (Blueprint $table) {
            $table->boolean('is_preview')->default(false)->after('card_number');
            $table->index(['deck_id', 'is_preview']);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['deck_id', 'is_preview']);
            $table->dropColumn('is_preview');
        });
    }
};
