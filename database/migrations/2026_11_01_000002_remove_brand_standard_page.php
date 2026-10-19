<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The Brand Standard & Trademark Notice page was withdrawn. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('legal_pages')->where('slug', 'brand-standard')->delete();
    }

    public function down(): void
    {
        // Not restored.
    }
};
