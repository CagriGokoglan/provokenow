<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The admin's "AI & Production" deck fields were never used; drop them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn(['ai_system_role_prompt', 'pod_card_box_theme', 'digital_delivery_file_name']);
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->text('ai_system_role_prompt')->nullable();
            $table->string('pod_card_box_theme')->nullable();
            $table->string('digital_delivery_file_name')->nullable();
        });
    }
};
