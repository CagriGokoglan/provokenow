<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            // One-to-one with users: the FK doubles as the primary key.
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('preferred_language', 10)->default('en');
            $table->string('digital_vault_passcode_hash')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
