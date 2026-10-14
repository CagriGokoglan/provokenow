<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('deck_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('card_number');
            $table->string('title');
            $table->jsonb('content_payload')->nullable();
            $table->timestamps();

            // Also serves as the deck_id lookup index (leftmost column).
            $table->unique(['deck_id', 'card_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cards ADD CONSTRAINT cards_card_number_check CHECK (card_number BETWEEN 1 AND 50)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
