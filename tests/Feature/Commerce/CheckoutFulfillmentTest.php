<?php

namespace Tests\Feature\Commerce;

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\Deck;
use App\Models\Product;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = [
        'name' => 'Ava Doe', 'line1' => '1 Main St', 'city' => 'Austin',
        'postal_code' => '73301', 'country' => 'US',
    ];

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin])->save();
    }

    public function test_paid_order_automatically_creates_entitlements_for_claimed_decks(): void
    {
        [$d1, $d2, $d3] = Deck::factory()->count(3)->withCards(2)->create();
        $single = Product::factory()->digitalDeck($d1, 2000)->create();
        $bundle = Product::factory()->state(['product_type' => ProductType::Bundle, 'price_cents' => 3500, 'requires_shipping' => false])
            ->hasAttached([$d2, $d3], [], 'decks')->create();

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/cart/add', ['product_id' => $single->id])->assertOk();
        $this->postJson('/api/v1/cart/add', ['product_id' => $bundle->id])->assertOk();

        $order = $this->postJson('/api/v1/checkout/intent')
            ->assertCreated()
            ->assertJsonPath('data.order.payment_status', 'pending')
            ->assertJsonPath('data.order.total_cents', 5500)
            ->assertJsonCount(2, 'data.order.items')
            ->json('data.order');

        $this->assertSame(0, UserEntitlement::count(), 'No access before payment.');
        $this->getJson("/api/v1/decks/{$d1->deck_code}")->assertJsonPath('data.is_locked', true);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $order['id']])
            ->assertOk()
            ->assertJsonPath('data.order.payment_status', 'paid')
            ->assertJsonPath('data.order.fulfillment_status', 'delivered')
            ->assertJsonCount(3, 'data.order.entitlements');

        $this->assertEqualsCanonicalizing(
            [$d1->id, $d2->id, $d3->id],
            UserEntitlement::where('user_id', $this->customer->id)->where('order_id', $order['id'])->pluck('deck_id')->all(),
        );

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/v1/decks/{$d1->deck_code}")->assertJsonPath('data.is_locked', false)->assertJsonCount(2, 'data.cards');
        $this->getJson('/api/v1/user/entitlements')->assertJsonCount(3, 'data.entitlements');
        $this->getJson('/api/v1/cart')->assertJsonCount(0, 'data.cart.items');

        // Owned now → cannot be bought again.
        $this->postJson('/api/v1/cart/add', ['product_id' => $single->id])->assertStatus(409);
    }

    public function test_fulfillment_is_idempotent(): void
    {
        $deck = Deck::factory()->create();
        $product = Product::factory()->digitalDeck($deck)->create();

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/cart/add', ['product_id' => $product->id]);
        $orderId = $this->postJson('/api/v1/checkout/intent')->json('data.order.id');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $orderId])->assertOk();
        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $orderId])->assertOk();

        $this->assertSame(1, UserEntitlement::where('user_id', $this->customer->id)->count());
    }

    public function test_fulfill_test_endpoint_is_staff_only(): void
    {
        $deck = Deck::factory()->create();
        $product = Product::factory()->digitalDeck($deck)->create();

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/cart/add', ['product_id' => $product->id]);
        $orderId = $this->postJson('/api/v1/checkout/intent')->json('data.order.id');

        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $orderId])
            ->assertForbidden()
            ->assertJsonPath('success', false);
        $this->assertSame(0, UserEntitlement::count());
    }

    public function test_guest_can_buy_physical_items_and_stock_is_decremented_on_payment(): void
    {
        $oil = Product::factory()->accessory([], 2400, stock: 5)->create();

        $token = $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id, 'quantity' => 2])
            ->assertOk()
            ->json('data.cart.cart_token');
        $this->assertNotEmpty($token);

        $guest = ['X-Cart-Token' => $token];
        $this->postJson('/api/v1/checkout/intent', ['email' => 'guest@example.com'], $guest)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shipping_address');

        $order = $this->postJson('/api/v1/checkout/intent', ['email' => 'guest@example.com', 'shipping_address' => self::ADDRESS], $guest)
            ->assertCreated()
            ->assertJsonPath('data.order.shipping_cents', 995)
            ->assertJsonPath('data.order.total_cents', 4800 + 995)
            ->json('data.order');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders/fulfill-test', ['order_number' => $order['order_number']])
            ->assertOk()
            ->assertJsonPath('data.order.fulfillment_status', 'unfulfilled');

        $this->assertSame(3, $oil->fresh()->stock_quantity);
    }

    public function test_guest_cannot_check_out_deck_access(): void
    {
        $product = Product::factory()->digitalDeck(Deck::factory()->create())->create();
        $token = $this->postJson('/api/v1/cart/add', ['product_id' => $product->id])->json('data.cart.cart_token');

        $this->postJson('/api/v1/checkout/intent', ['email' => 'guest@example.com'], ['X-Cart-Token' => $token])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_checkout_rejects_items_that_went_out_of_stock(): void
    {
        $oil = Product::factory()->accessory([], stock: 2)->create();

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id, 'quantity' => 3])->assertStatus(409);
        $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id, 'quantity' => 2])->assertOk();

        $oil->update(['stock_quantity' => 1]);

        $this->postJson('/api/v1/checkout/intent', ['shipping_address' => self::ADDRESS])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors']);
    }
}
