<?php

namespace Tests\Feature\Commerce;

use App\Contracts\PaymentGateway;
use App\Enums\EntitlementStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTransactionalEmail;
use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Payments\CheckoutRedirect;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    private Deck $deck;

    private Product $digital;

    private Product $box;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.secret' => 'sk_test_x',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.stripe.shipping_countries' => ['US', 'GB'],
            'services.meta.pixel_id' => '1',
            'services.meta.capi_access_token' => 't',
        ]);
        Queue::fake();

        $this->deck = Deck::factory()->create(['deck_code' => 'DECK-020']);
        $this->digital = Product::factory()->digitalDeck($this->deck, 1900)->create(['name' => 'After Midnight Digital', 'sku' => 'DIG-020']);
        $this->box = Product::factory()->podDeck($this->deck, 3900)->create(['name' => 'After Midnight Box', 'sku' => 'BOX-020']);
    }

    /** Fake hosted checkout: records the order it was asked to charge. */
    private function fakeGateway(bool $fails = false): object
    {
        $fake = new class($fails) implements PaymentGateway
        {
            public ?Order $order = null;

            public function __construct(private bool $fails) {}

            public function name(): string
            {
                return 'stripe';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function createCheckout(Order $order): CheckoutRedirect
            {
                if ($this->fails) {
                    throw new RuntimeException('Stripe is down');
                }
                $this->order = $order;

                return new CheckoutRedirect('https://checkout.stripe.com/c/pay/cs_test_abc', 'cs_test_'.str_replace('-', '', $order->id));
            }
        };
        $this->app->instance(PaymentGateway::class, $fake);

        return $fake;
    }

    /** Signs a payload exactly like Stripe (t=…,v1=HMAC-SHA256). */
    private function webhook(string $type, array $object, ?string $secret = null)
    {
        $payload = json_encode(['id' => 'evt_'.uniqid(), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", $secret ?? self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** Signed-in customer buys the digital deck + the bundled box; returns the pending order. */
    private function startCheckout(?User $user = null): Order
    {
        $this->fakeGateway();
        Sanctum::actingAs($user ?? User::factory()->create(['email' => 'ava@example.com']));
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id])->assertOk();
        $this->postJson('/api/v1/cart/apply-upsell', ['product_id' => $this->box->id])->assertOk();

        $this->withHeaders(['X-Tracking-Consent' => 'granted'])
            ->postJson('/api/v1/checkout/session')
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/cs_test_abc');

        return Order::firstOrFail();
    }

    private function completedSession(Order $order, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $order->metadata['stripe_session_id'],
            'object' => 'checkout.session',
            'client_reference_id' => $order->id,
            'metadata' => ['order_id' => $order->id, 'order_number' => $order->order_number],
            'payment_status' => 'paid',
            'amount_total' => $order->total_cents,
            'currency' => strtolower($order->currency),
            'payment_intent' => 'pi_test_123',
            'customer_details' => [
                'email' => 'ava@example.com', 'name' => 'Ava Doe', 'phone' => '+15550100',
                'address' => ['line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '73301', 'country' => 'US'],
            ],
            'collected_information' => ['shipping_details' => [
                'name' => 'Ava Doe',
                'address' => ['line1' => '1 Main St', 'line2' => null, 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '73301', 'country' => 'US'],
            ]],
        ], $overrides);
    }

    public function test_checkout_session_creates_pending_order_and_returns_redirect(): void
    {
        $order = $this->startCheckout();

        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame('stripe', $order->payment_gateway);
        $this->assertStringStartsWith('cs_test_', $order->metadata['stripe_session_id']);
        $this->assertNull($order->shipping_address, 'Stripe collects the address.');
        $this->assertSame(1900 + 3315 + 995, $order->total_cents);
        Queue::assertPushed(SendMetaConversionEvent::class, fn ($j) => $j->event['event_name'] === 'InitiateCheckout');
    }

    public function test_stripe_session_params_charge_exactly_the_order_total(): void
    {
        $order = $this->startCheckout();
        $params = app(StripeGateway::class)->sessionParams($order);

        $lines = array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $params['line_items']));
        $shipping = $params['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'];
        $this->assertSame($order->total_cents, $lines + $shipping);
        $this->assertSame(['US', 'GB'], $params['shipping_address_collection']['allowed_countries']);
        $this->assertSame('ava@example.com', $params['customer_email']);
        $this->assertSame($order->id, $params['metadata']['order_id']);
        $this->assertStringContainsString('{CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertGreaterThanOrEqual(now()->addMinutes(29)->getTimestamp(), $params['expires_at']);
        // The bundle discount is baked into the box line.
        $this->assertContains(3315, array_column(array_column($params['line_items'], 'price_data'), 'unit_amount'));
    }

    public function test_completed_webhook_fulfils_order_grants_access_and_queues_email_and_capi(): void
    {
        $order = $this->startCheckout();

        $this->webhook('checkout.session.completed', $this->completedSession($order))->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('pi_test_123', $order->transaction_id);
        $this->assertSame(FulfillmentStatus::Unfulfilled, $order->fulfillment_status); // box still ships
        $this->assertEquals(['name' => 'Ava Doe', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '73301', 'country' => 'US', 'phone' => '+15550100'], $order->shipping_address);
        $this->assertTrue($order->user->hasAccessToDeck($this->deck));

        Queue::assertPushed(SendTransactionalEmail::class, fn ($j) => $j->orderId === $order->id && $j->type === SendTransactionalEmail::ORDER_CONFIRMATION);
        Queue::assertPushed(SendMetaConversionEvent::class, fn ($j) => $j->event['event_name'] === 'Purchase' && $j->event['event_id'] === "purchase.{$order->id}");

        // Stripe retries / duplicate deliveries change nothing.
        $this->webhook('checkout.session.completed', $this->completedSession($order))->assertOk();
        Queue::assertPushed(SendTransactionalEmail::class, 1);
        $this->assertSame(1, $order->entitlements()->count());

        // The thank-you page can read the outcome by session id.
        $this->getJson("/api/v1/checkout/status/{$order->metadata['stripe_session_id']}")
            ->assertOk()
            ->assertJsonPath('data.order.payment_status', 'paid')
            ->assertJsonPath('data.order.unlocked_decks.0.deck_code', 'DECK-020');
    }

    public function test_guest_physical_order_gets_email_and_address_from_stripe(): void
    {
        $this->fakeGateway();
        $oil = Product::factory()->accessory([], 2400)->create();
        $token = $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id])->json('data.cart.cart_token');

        $this->postJson('/api/v1/checkout/session', [], ['X-Cart-Token' => $token])->assertCreated();
        $order = Order::firstOrFail();
        $this->assertNull($order->customer_email);

        $this->webhook('checkout.session.completed', $this->completedSession($order, ['customer_details' => ['email' => 'guest@example.com']]))->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('guest@example.com', $order->customer_email);
        $this->assertSame(24, $oil->fresh()->stock_quantity);
    }

    public function test_amount_mismatch_is_not_fulfilled(): void
    {
        $order = $this->startCheckout();

        $this->webhook('checkout.session.completed', $this->completedSession($order, ['amount_total' => 100]))->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame('amount_mismatch', $order->metadata['payment_review']['reason']);
        $this->assertFalse($order->user->hasAccessToDeck($this->deck));
    }

    public function test_session_for_another_order_is_ignored(): void
    {
        $order = $this->startCheckout();

        $this->webhook('checkout.session.completed', $this->completedSession($order, ['id' => 'cs_test_someone_else']))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_unsigned_or_forged_webhooks_are_rejected(): void
    {
        $order = $this->startCheckout();

        $this->webhook('checkout.session.completed', $this->completedSession($order), 'whsec_wrong')->assertStatus(400);
        $this->postJson('/api/v1/webhooks/stripe', ['type' => 'checkout.session.completed'])->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_delayed_payment_waits_for_async_success_and_expired_sessions_fail(): void
    {
        $order = $this->startCheckout();

        $this->webhook('checkout.session.completed', $this->completedSession($order, ['payment_status' => 'unpaid']))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        $this->webhook('checkout.session.async_payment_succeeded', $this->completedSession($order))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);

        // A late "expired" event never downgrades a paid order.
        $this->webhook('checkout.session.expired', $this->completedSession($order, ['payment_status' => 'unpaid']))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_expired_session_marks_pending_order_failed(): void
    {
        $order = $this->startCheckout();

        $this->webhook('checkout.session.expired', $this->completedSession($order, ['payment_status' => 'unpaid']))->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertSame('checkout_expired', $order->metadata['failure_reason']);
    }

    public function test_full_refund_revokes_access_partial_refund_is_recorded(): void
    {
        $order = $this->startCheckout();
        $this->webhook('checkout.session.completed', $this->completedSession($order))->assertOk();

        $this->webhook('charge.refunded', ['object' => 'charge', 'payment_intent' => 'pi_test_123', 'amount_refunded' => 995, 'refunded' => false])->assertOk();
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(995, $order->metadata['refunded_cents']);

        $this->webhook('charge.refunded', ['object' => 'charge', 'payment_intent' => 'pi_test_123', 'amount_refunded' => $order->total_cents, 'refunded' => true])->assertOk();
        $order->refresh();
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(EntitlementStatus::Revoked, $order->entitlements()->first()->status);
        $this->assertFalse($order->user->hasAccessToDeck($this->deck));
    }

    public function test_guests_cannot_buy_deck_access_and_gateway_errors_fail_cleanly(): void
    {
        $this->fakeGateway();
        $token = $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id])->json('data.cart.cart_token');
        $this->postJson('/api/v1/checkout/session', [], ['X-Cart-Token' => $token])->assertUnauthorized();

        $this->fakeGateway(fails: true);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);
        $this->postJson('/api/v1/checkout/session')->assertStatus(502)->assertJsonPath('success', false);
        $this->assertSame(PaymentStatus::Failed, Order::firstOrFail()->payment_status);
    }

    public function test_checkout_is_unavailable_until_stripe_is_configured(): void
    {
        config(['services.stripe.secret' => null, 'provoke.enable_test_fulfillment' => false]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);

        $this->postJson('/api/v1/checkout/session')->assertStatus(503);
        $this->postJson('/api/v1/webhooks/stripe')->assertStatus(400);
    }
}
