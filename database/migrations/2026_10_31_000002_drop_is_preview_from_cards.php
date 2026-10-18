<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hand-picked preview card feature was withdrawn. Removes its column wherever the earlier
 * migration already ran; does nothing where it never did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cards', 'is_preview')) {
            return;
        }

        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['deck_id', 'is_preview']);
            $table->dropColumn('is_preview');
        });
    }

    public function down(): void
    {
        // Nothing to restore: the feature is gone.
    }
};
