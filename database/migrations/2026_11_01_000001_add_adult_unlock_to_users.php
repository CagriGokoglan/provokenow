<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // +18 "Passion mode": a verified member's own choice to see the Advanced and Taboo tiers.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_adult_unlocked')->default(false)->after('email_verified_at');
            $table->timestamp('adult_unlocked_at')->nullable()->after('is_adult_unlocked');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_adult_unlocked', 'adult_unlocked_at']);
        });
    }
};
