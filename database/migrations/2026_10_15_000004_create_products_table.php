<?php

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('product_type', ProductType::values());
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('sale_price_cents')->nullable();
            // -1 = unlimited (digital / print-on-demand).
            $table->integer('stock_quantity')->default(-1);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'product_type']);
        });

        Schema::create('product_deck_claims', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deck_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_id', 'deck_id']);
            $table->index('deck_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_quantity_check CHECK (stock_quantity >= -1)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_sale_price_check CHECK (sale_price_cents IS NULL OR sale_price_cents <= price_cents)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_deck_claims');
        Schema::dropIfExists('products');
    }
};
