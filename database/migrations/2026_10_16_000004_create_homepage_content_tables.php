<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Admin-managed homepage content: hero slides, curated Instagram posts, site settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('eyebrow', 80)->nullable();
            $table->string('title', 120);
            $table->string('subtitle', 255)->nullable();
            $table->string('image');                 // public-disk path or absolute URL
            $table->string('image_mobile')->nullable();
            // Where the CTA goes: a deck, a product, or a custom URL.
            $table->string('target_type', 16)->default('custom');
            $table->foreignUuid('deck_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_url')->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE hero_slides ADD CONSTRAINT hero_slides_target_check CHECK (target_type IN ('deck','product','custom'))");
        }

        Schema::create('instagram_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('post_url');
            $table->string('image');                 // cover shown on our site
            $table->string('caption', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('instagram_posts');
        Schema::dropIfExists('hero_slides');
    }
};
