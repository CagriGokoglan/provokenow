<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Intensity is rated 1 (lowest) to 5 (highest). Existing ratings keep their number. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE decks DROP CONSTRAINT IF EXISTS decks_intensity_level_check');
            DB::statement('ALTER TABLE decks ADD CONSTRAINT decks_intensity_level_check CHECK (intensity_level BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::table('decks')->where('intensity_level', 5)->update(['intensity_level' => 4]);
            DB::statement('ALTER TABLE decks DROP CONSTRAINT IF EXISTS decks_intensity_level_check');
            DB::statement('ALTER TABLE decks ADD CONSTRAINT decks_intensity_level_check CHECK (intensity_level BETWEEN 1 AND 4)');
        }
    }
};
