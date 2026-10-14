<?php

use App\Enums\EntitlementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('deck_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', EntitlementStatus::values())->default(EntitlementStatus::Active->value);
            $table->timestamps();

            // Hot path: "does user X have access to deck Y?"
            $table->index(['user_id', 'deck_id', 'status']);
            $table->index('deck_id');
            $table->index('order_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            // At most one active entitlement per user/deck; revoked rows are kept as history.
            DB::statement("CREATE UNIQUE INDEX user_entitlements_one_active_per_deck ON user_entitlements (user_id, deck_id) WHERE status = 'active'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entitlements');
    }
};
