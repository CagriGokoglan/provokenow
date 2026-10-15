<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store categories (Dice, Lingerie & Underwear, …) and merchandising flags that drive the
 * homepage sections: Popular (is_featured), New Arrivals (is_new_arrival) and
 * Printed Game Boxes (is_boxed_game).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new_arrival')->default(false);
            $table->boolean('is_boxed_game')->default(false);
            // Manual order inside showcases (lower first).
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index('product_category_id');
            $table->index(['is_active', 'is_featured']);
            $table->index(['is_active', 'is_new_arrival']);
            $table->index(['is_active', 'is_boxed_game']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_category_id');
            $table->dropIndex(['is_active', 'is_featured']);
            $table->dropIndex(['is_active', 'is_new_arrival']);
            $table->dropIndex(['is_active', 'is_boxed_game']);
            $table->dropColumn(['is_featured', 'is_new_arrival', 'is_boxed_game', 'sort_order']);
        });

        Schema::dropIfExists('product_categories');
    }
};
