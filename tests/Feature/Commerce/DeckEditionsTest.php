<?php

namespace Tests\Feature\Commerce;

use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Services\Commerce\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Digital ($12.99) vs physical box ($49.99) editions of one deck, and the upgrade credit. */
class DeckEditionsTest extends TestCase
{
    use RefreshDatabase;

    private Deck $deck;

    private Product $digital;

    private Product $box;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->deck = Deck::factory()->create(['deck_code' => 'DECK-010']);
        $this->digital = Product::factory()->digitalDeck($this->deck, 1299)->create(['slug' => 'dig-deck-010']);
        $this->box = Product::factory()->podDeck($this->deck, 4999)->create(['slug' => 'box-deck-010', 'is_boxed_game' => true, 'stock_quantity' => -1]);
    }

    /** Pays for the products through the real fulfilment path (entitlements, metadata). */
    private function buy(User $user, Product $product, int $paidCents): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'customer_email' => $user->email, 'subtotal_cents' => $paidCents, 'total_cents' => $paidCents,
            'metadata' => ['requires_shipping' => $product->requiresShipping()],
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'quantity' => 1, 'unit_price_cents' => $product->price_cents, 'subtotal_cents' => $paidCents,
            'metadata' => ['name' => $product->name, 'deck_ids' => [$this->deck->id], 'requires_shipping' => $product->requiresShipping()],
        ]);

        return app(OrderFulfillmentService::class)->markPaid($order, 'manual', 'ref_'.uniqid());
    }

    private function editions(?User $user = null): array
    {
        if ($user) {
            Sanctum::actingAs($user);
        }

        return $this->getJson('/api/v1/decks/deck-010')->assertOk()->json('data.editions');
    }

    private function addToCart(User $user, Product $product, int $quantity = 1): array
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/cart/add', ['product_id' => $product->id, 'quantity' => $quantity])->assertOk()->json('data.cart');
    }

    public function test_guests_see_both_editions_at_full_price(): void
    {
        $editions = $this->editions();
        $this->assertSame(['dig-deck-010', $this->digital->id, 1299], [$editions['digital']['product_slug'], $editions['digital']['product_id'], $editions['digital']['price_cents']]);
        $this->assertSame(['box-deck-010', $this->box->id, 4999, true, true], [
            $editions['physical']['product_slug'], $editions['physical']['product_id'], $editions['physical']['price_cents'],
            $editions['physical']['requires_shipping'], $editions['physical']['in_stock'],
        ]);
        $this->assertSame($this->box->id, $editions['physical']['product']['id'], 'Full product for the cart button.');

        unset($editions['digital'], $editions['physical']);
        $this->assertSame([
            'user_owns_digital' => false,
            'user_owns_physical' => false,
            'is_eligible_for_upgrade_discount' => false,
            'upgrade_discount_cents' => 0,
            'final_physical_price_cents' => 4999,
        ], $editions);
    }

    public function test_buying_the_box_unlocks_the_digital_deck_immediately(): void
    {
        $user = User::factory()->create();
        $this->buy($user, $this->box, 4999);

        $this->assertTrue($user->fresh()->hasAccessToDeck($this->deck), 'Rule A: physical includes digital.');
        $editions = $this->editions($user);
        $this->assertTrue($editions['user_owns_digital']);
        $this->assertTrue($editions['user_owns_physical']);
        $this->assertFalse($editions['is_eligible_for_upgrade_discount']);

        // A second box (a gift) is full price.
        $this->assertSame(0, $this->addToCart($user, $this->box)['totals']['digital_credit_cents']);
    }

    public function test_digital_owners_get_what_they_paid_back_on_their_first_box(): void
    {
        $user = User::factory()->create();
        $this->buy($user, $this->digital, 1299);

        $editions = $this->editions($user);
        $this->assertTrue($editions['user_owns_digital']);
        $this->assertFalse($editions['user_owns_physical']);
        $this->assertTrue($editions['is_eligible_for_upgrade_discount']);
        $this->assertSame([1299, 3700], [$editions['upgrade_discount_cents'], $editions['final_physical_price_cents']]);

        // Rule B in the cart: two boxes, the credit applies to one.
        $cart = $this->addToCart($user, $this->box, 2);
        $line = $cart['items'][0];
        $this->assertSame([9998, 1299, 8699], [$line['line_subtotal_cents'], $line['digital_credit_cents'], $line['line_total_cents']]);
        $this->assertSame(1299, $cart['totals']['digital_credit_cents']);
    }

    public function test_the_credit_is_used_once(): void
    {
        $user = User::factory()->create();
        $this->buy($user, $this->digital, 1299);
        $this->buy($user, $this->box, 3700);

        $editions = $this->editions($user);
        $this->assertTrue($editions['user_owns_physical']);
        $this->assertSame([0, 4999], [$editions['upgrade_discount_cents'], $editions['final_physical_price_cents']]);
    }

    public function test_the_credit_matches_what_was_actually_paid_and_free_access_earns_none(): void
    {
        $discounted = User::factory()->create();
        $this->buy($discounted, $this->digital, 999); // bought on sale
        $this->assertSame(999, $this->editions($discounted)['upgrade_discount_cents']);

        $gifted = User::factory()->create();
        DB::transaction(fn () => app(EntitlementService::class)->grant($gifted, [$this->deck->id]));
        $editions = $this->editions($gifted);
        $this->assertTrue($editions['user_owns_digital']);
        $this->assertFalse($editions['is_eligible_for_upgrade_discount']);
    }
}
