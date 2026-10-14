<?php

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number', 32)->unique();
            // Nullable for guest checkout; keep order history if the account is deleted.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Contact for guest orders (and a snapshot for registered ones).
            $table->string('customer_email')->nullable()->index();
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->enum('payment_status', PaymentStatus::values())->default(PaymentStatus::Pending->value);
            $table->enum('fulfillment_status', FulfillmentStatus::values())->default(FulfillmentStatus::Unfulfilled->value);
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['payment_status', 'fulfillment_status']);
            $table->index(['payment_gateway', 'transaction_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            // Products that have been sold must not be hard-deleted; deactivate instead.
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('subtotal_cents');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('product_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
