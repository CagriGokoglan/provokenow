<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->string('meta_title', 60)->nullable();
            $table->string('meta_description', 155)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_image')->nullable();
            // Curated Q&A for FAQPage schema: [{"question": "...", "answer": "..."}].
            $table->jsonb('seo_faq')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_title', 60)->nullable();
            $table->string('meta_description', 155)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'canonical_url', 'og_image', 'seo_faq']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'canonical_url', 'og_image']);
        });
    }
};
