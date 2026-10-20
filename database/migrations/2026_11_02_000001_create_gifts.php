<?php

use App\Enums\GiftStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A gift is a paid order sent to someone else's email. Digital decks are claimed into the
        // recipient's Vault; physical items ship to the address the buyer gave at checkout.
        Schema::create('gifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->foreignUuid('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_alias', 100)->nullable();
            $table->text('gift_message')->nullable();
            // When the recipient is told (null = as soon as payment clears).
            $table->timestamp('deliver_at')->nullable();
            // Only a hash is stored; the raw token lives in the recipient's email link.
            $table->string('claim_token_hash', 64)->nullable()->unique();
            $table->enum('status', GiftStatus::values())->default(GiftStatus::Pending->value);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deliver_at']);
            $table->index(['recipient_id', 'status']);
            $table->index('recipient_email');
            $table->index('sender_id');
        });

        // Vault items remember where they came from.
        Schema::table('user_entitlements', function (Blueprint $table) {
            $table->string('acquired_via', 20)->default('purchase')->after('order_id');
            $table->foreignUuid('gift_id')->nullable()->after('acquired_via')->constrained()->nullOnDelete();
            $table->index('gift_id');
        });

        // "I am 18+" confirmations (gift claims), kept for compliance.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('age_confirmed_at')->nullable()->after('adult_unlocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('age_confirmed_at'));
        Schema::table('user_entitlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_id');
            $table->dropColumn('acquired_via');
        });
        Schema::dropIfExists('gifts');
    }
};
