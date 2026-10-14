<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('subtotal_cents')->default(0)->after('customer_email');
            $table->unsignedInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->char('currency', 3)->default('USD')->after('shipping_cents');
            $table->timestamp('paid_at')->nullable()->after('transaction_id');
            $table->jsonb('metadata')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal_cents', 'discount_cents', 'currency', 'paid_at', 'metadata']);
        });
    }
};
