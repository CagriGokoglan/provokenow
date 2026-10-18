<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Journal: posts in categories, organised as topic clusters around pillar posts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_category_id')->nullable()->constrained()->nullOnDelete();
            // Topic cluster: a supporting post points at its pillar post (FK added below).
            $table->uuid('pillar_post_id')->nullable();
            $table->boolean('is_pillar')->default(false);
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('excerpt', 300)->nullable();
            $table->text('tldr')->nullable();
            $table->longText('content');                 // Markdown
            $table->string('featured_image')->nullable();
            $table->string('seo_title', 60)->nullable();
            $table->string('seo_description', 155)->nullable();
            $table->string('author_name', 120)->nullable();
            $table->string('author_role', 160)->nullable();
            $table->text('author_bio')->nullable();
            $table->jsonb('faqs')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreign('pillar_post_id')->references('id')->on('posts')->nullOnDelete();
        });

        Schema::create('post_deck', function (Blueprint $table) {
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deck_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['post_id', 'deck_id']);
        });

        Schema::create('post_product', function (Blueprint $table) {
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['post_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_product');
        Schema::dropIfExists('post_deck');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('post_categories');
    }
};
