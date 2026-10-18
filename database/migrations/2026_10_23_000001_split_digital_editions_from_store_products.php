<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digital decks are sold from the deck itself (price, SKU, on sale) and kept out of the store;
 * a physical boxed game links to exactly one deck; shipping is a real flag on products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->unsignedInteger('digital_price_cents')->nullable()->after('intensity_level');
            $table->string('digital_sku', 64)->nullable()->unique()->after('digital_price_cents');
            $table->boolean('is_digital_sale_active')->default(false)->after('digital_sku');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_shipping')->default(true)->after('product_type');
            // The deck a physical boxed game (or a deck's own digital edition) belongs to.
            $table->foreignUuid('linked_deck_id')->nullable()->after('requires_shipping')->constrained('decks')->nullOnDelete();
            // One physical box and one digital edition per deck.
            $table->unique(['product_type', 'linked_deck_id']);
        });

        $claims = fn (string $productId) => DB::table('product_deck_claims')->where('product_id', $productId)->pluck('deck_id');

        foreach (DB::table('products')->orderBy('created_at')->get() as $product) {
            $meta = json_decode($product->metadata ?? 'null', true) ?: [];
            $update = ['requires_shipping' => array_key_exists('requires_shipping', $meta)
                ? (bool) $meta['requires_shipping']
                : $product->product_type !== 'digital_deck'];

            $deckIds = $claims($product->id);
            $deckId = $deckIds->count() === 1 ? $deckIds->first() : null;
            $taken = fn (string $type) => $deckId && DB::table('products')->where('product_type', $type)->where('linked_deck_id', $deckId)->exists();

            if ($product->product_type === 'pod_physical_deck' && $deckId && ! $taken('pod_physical_deck')) {
                $update['linked_deck_id'] = $deckId;
            }

            if ($product->product_type === 'digital_deck' && $deckId && ! $taken('digital_deck')) {
                $update['linked_deck_id'] = $deckId;
                DB::table('decks')->where('id', $deckId)->update([
                    'digital_price_cents' => $product->price_cents,
                    'digital_sku' => DB::table('decks')->where('digital_sku', $product->sku)->exists() ? null : $product->sku,
                    'is_digital_sale_active' => $product->is_active,
                ]);
            }

            DB::table('products')->where('id', $product->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['product_type', 'linked_deck_id']);
            $table->dropConstrainedForeignId('linked_deck_id');
            $table->dropColumn('requires_shipping');
        });

        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn(['digital_price_cents', 'digital_sku', 'is_digital_sale_active']);
        });
    }
};
