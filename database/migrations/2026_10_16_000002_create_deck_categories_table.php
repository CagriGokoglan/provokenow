<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Admin-managed fantasy / content tags for decks (BDSM, Roleplay, Sensory, …). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deck_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('deck_category_deck', function (Blueprint $table) {
            $table->foreignUuid('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deck_category_id')->constrained()->cascadeOnDelete();

            $table->primary(['deck_id', 'deck_category_id']);
            $table->index('deck_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_category_deck');
        Schema::dropIfExists('deck_categories');
    }
};
