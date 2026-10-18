<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The digital edition's sale price, edited on the deck like its price. Brought over from the
 * digital product, where it could no longer be seen or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->unsignedInteger('digital_sale_price_cents')->nullable()->after('digital_price_cents');
        });

        DB::table('products')
            ->where('product_type', 'digital_deck')
            ->whereNotNull('linked_deck_id')
            ->whereNotNull('sale_price_cents')
            ->get(['linked_deck_id', 'sale_price_cents'])
            ->each(fn (object $p) => DB::table('decks')->where('id', $p->linked_deck_id)->update(['digital_sale_price_cents' => $p->sale_price_cents]));
    }

    public function down(): void
    {
        Schema::table('decks', fn (Blueprint $table) => $table->dropColumn('digital_sale_price_cents'));
    }
};
