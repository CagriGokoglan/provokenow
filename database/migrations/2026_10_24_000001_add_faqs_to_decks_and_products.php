<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Editable FAQ lists ({question, answer}) on decks and products; shown on the page and as FAQPage schema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', fn (Blueprint $table) => $table->renameColumn('seo_faq', 'faqs'));
        Schema::table('products', fn (Blueprint $table) => $table->jsonb('faqs')->nullable()->after('description'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('faqs'));
        Schema::table('decks', fn (Blueprint $table) => $table->renameColumn('faqs', 'seo_faq'));
    }
};
