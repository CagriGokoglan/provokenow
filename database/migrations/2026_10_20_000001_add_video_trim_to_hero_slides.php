<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional trim for a slide's YouTube video: skip the first / cut the last N seconds. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->unsignedSmallInteger('video_start')->default(0)->after('video_url');
            $table->unsignedSmallInteger('video_end_trim')->default(0)->after('video_start');
        });
    }

    public function down(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->dropColumn(['video_start', 'video_end_trim']);
        });
    }
};
