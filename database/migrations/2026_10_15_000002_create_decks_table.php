<?php

use App\Enums\DeckSegment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('deck_code', 20)->unique();
            $table->string('title');
            $table->enum('segment', DeckSegment::values());
            $table->unsignedSmallInteger('intensity_level');
            $table->text('core_theme_keywords')->nullable();
            $table->text('ai_system_role_prompt')->nullable();
            $table->string('pod_card_box_theme')->nullable();
            $table->string('digital_delivery_file_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'segment', 'intensity_level']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE decks ADD CONSTRAINT decks_intensity_level_check CHECK (intensity_level BETWEEN 1 AND 4)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('decks');
    }
};
