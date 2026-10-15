<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hero slides can play a looping video (the image becomes its poster), and media is optional:
 * a slide without any falls back to the brand backdrop on the web app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->string('image')->nullable()->change();
            $table->string('video_url')->nullable()->after('image_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->dropColumn('video_url');
        });
    }
};
