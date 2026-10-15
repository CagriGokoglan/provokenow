<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Provoke Club waitlist (double opt-in can be layered on with Brevo later).
        Schema::create('club_signups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 40)->default('homepage');
            $table->timestamp('consented_at');
            $table->timestamps();
        });

        // Account address book (Settings).
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('name');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 20);
            $table->char('country', 2);
            $table->string('phone', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('club_signups');
    }
};
