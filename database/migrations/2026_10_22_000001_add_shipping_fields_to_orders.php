<?php

use App\Enums\FulfillmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping details become real columns (they were loose keys in orders.metadata), and an order
 * can be cancelled from the fulfilment flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_name', 80)->nullable()->after('fulfillment_status');
            $table->string('tracking_number', 100)->nullable()->after('courier_name');
            $table->string('tracking_url', 500)->nullable()->after('tracking_number');
            $table->timestamp('shipped_at')->nullable()->after('tracking_url');
            $table->date('estimated_delivery_date')->nullable()->after('shipped_at');
            $table->timestamp('delivered_at')->nullable()->after('estimated_delivery_date');
        });

        DB::table('orders')->whereNotNull('metadata')->orderBy('id')->each(function (object $order) {
            $meta = json_decode($order->metadata, true) ?: [];
            if (! isset($meta['tracking_number']) && ! isset($meta['tracking_url'])) {
                return;
            }
            DB::table('orders')->where('id', $order->id)->update([
                'tracking_number' => $meta['tracking_number'] ?? null,
                'tracking_url' => $meta['tracking_url'] ?? null,
                'metadata' => json_encode(array_diff_key($meta, array_flip(['tracking_number', 'tracking_url']))),
            ]);
        });

        $this->allowStatuses(FulfillmentStatus::values());
    }

    public function down(): void
    {
        DB::table('orders')->where('fulfillment_status', 'cancelled')->update(['fulfillment_status' => 'unfulfilled']);
        $this->allowStatuses(['unfulfilled', 'processing', 'shipped', 'delivered']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_name', 'tracking_number', 'tracking_url', 'shipped_at', 'estimated_delivery_date', 'delivered_at']);
        });
    }

    /** Laravel's enum() is a CHECK constraint on PostgreSQL. */
    private function allowStatuses(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $list = implode(', ', array_map(fn ($v) => "'{$v}'::character varying", $values));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_fulfillment_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_status_check CHECK (fulfillment_status::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
