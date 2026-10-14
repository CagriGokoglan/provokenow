<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Deck;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cart_is_identified_by_token_and_merges_into_account_on_sign_in(): void
    {
        $deck = Product::factory()->digitalDeck(Deck::factory()->create())->create();
        $oil = Product::factory()->accessory([], 2400)->create();

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.cart', null);

        $token = $this->postJson('/api/v1/cart/add', ['product_id' => $deck->id])->json('data.cart.cart_token');
        $this->postJson('/api/v1/cart/add', ['product_id' => $deck->id], ['X-Cart-Token' => $token])
            ->assertJsonPath('data.cart.items.0.quantity', 1); // digital licences stay at 1
        $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id, 'quantity' => 2], ['X-Cart-Token' => $token])
            ->assertJsonPath('data.cart.totals.subtotal_cents', 2000 + 4800);

        // Unknown tokens never resolve to someone else's cart.
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => 'nope'])->assertJsonPath('data.cart', null);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])
            ->assertJsonPath('data.cart.cart_token', null)
            ->assertJsonCount(2, 'data.cart.items');

        $this->assertSame(1, Cart::count());
        $this->assertSame($user->id, Cart::first()->user_id);
    }

    public function test_cannot_remove_items_from_another_cart(): void
    {
        $product = Product::factory()->accessory()->create();
        $tokenA = $this->postJson('/api/v1/cart/add', ['product_id' => $product->id])->json('data.cart.cart_token');
        $itemId = $this->getJson('/api/v1/cart', ['X-Cart-Token' => $tokenA])->json('data.cart.items.0.id');

        $tokenB = $this->postJson('/api/v1/cart/add', ['product_id' => $product->id])->json('data.cart.cart_token');
        $this->assertNotSame($tokenA, $tokenB);

        $this->deleteJson("/api/v1/cart/items/{$itemId}", [], ['X-Cart-Token' => $tokenB])->assertNotFound();
        $this->deleteJson("/api/v1/cart/items/{$itemId}", [], ['X-Cart-Token' => $tokenA])
            ->assertOk()
            ->assertJsonCount(0, 'data.cart.items');
    }

    public function test_inactive_products_cannot_be_added(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->postJson('/api/v1/cart/add', ['product_id' => $product->id])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }
}
