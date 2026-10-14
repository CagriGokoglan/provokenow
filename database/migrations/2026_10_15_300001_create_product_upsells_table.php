<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-curated upsell rules: "when `product_id` is in the cart, offer `upsell_product_id`
     * at `discount_percent` off". Complements the implicit rules in UpsellEngine.
     */
    public function up(): void
    {
        Schema::create('product_upsells', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('upsell_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('discount_percent')->default(15);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'upsell_product_id']);
            $table->index('upsell_product_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_upsells ADD CONSTRAINT product_upsells_discount_check CHECK (discount_percent BETWEEN 0 AND 90)');
            DB::statement('ALTER TABLE product_upsells ADD CONSTRAINT product_upsells_not_self_check CHECK (product_id <> upsell_product_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_upsells');
    }
};
