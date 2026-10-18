<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PostgreSQL doesn't index foreign keys by itself; these are joined or filtered on. */
return new class extends Migration
{
    private const INDEXES = [
        'products' => ['linked_deck_id'],
        'order_items' => ['order_id'],
        'cart_items' => ['upsell_anchor_item_id'],
        'hero_slides' => ['deck_id', 'product_id'],
        'club_signups' => ['user_id'],
        'posts' => ['pillar_post_id', 'post_category_id'],
        'post_deck' => ['deck_id'],
        'post_product' => ['product_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->dropIndex([$column]);
                }
            });
        }
    }
};
