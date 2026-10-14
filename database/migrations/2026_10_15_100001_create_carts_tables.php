<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Exactly one of user_id (signed in) or token (guest) identifies the cart.
            $table->foreignUuid('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index('updated_at'); // for pruning abandoned guest carts
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            // The digital-deck line that unlocked this item's bundle discount (if any).
            $table->uuid('upsell_anchor_item_id')->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreign('upsell_anchor_item_id')->references('id')->on('cart_items')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE carts ADD CONSTRAINT carts_owner_check CHECK ((user_id IS NULL) <> (token IS NULL))');
            DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
