<?php

namespace Tests\Feature\Commerce;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTransactionalEmail;
use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketing\BrevoEmailService;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Without Stripe keys, non-production checkout runs through the TEST MODE gateway. */
class TestModeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Deck $deck;

    private Product $digital;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'provoke.enable_test_fulfillment' => true,
            'provoke.frontend.url' => 'http://localhost:3000',
            'services.meta.pixel_id' => '1',
            'services.meta.capi_access_token' => 't',
        ]);
        Queue::fake();

        $this->deck = Deck::factory()->create(['deck_code' => 'DECK-001']);
        $this->digital = Product::factory()->digitalDeck($this->deck, 1900)->create();
    }

    private function startCheckout(array $headers = []): string
    {
        $url = $this->postJson('/api/v1/checkout/session', [], $headers)->assertCreated()->json('data.checkout_url');
        $this->assertStringStartsWith('http://localhost:3000/checkout/test?session_id=cs_test_', $url);

        return substr($url, strpos($url, 'session_id=') + 11);
    }

    public function test_signed_in_customer_completes_test_payment_with_full_fulfilment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);

        $session = $this->startCheckout(['X-Tracking-Consent' => 'granted']);
        $this->getJson("/api/v1/checkout/status/{$session}")
            ->assertJsonPath('data.order.payment_status', 'pending')
            ->assertJsonPath('data.order.payment_gateway', 'test')
            ->assertJsonPath('data.order.needs_email', false)
            ->assertJsonPath('data.order.needs_shipping_address', false);

        $this->postJson("/api/v1/checkout/test/{$session}/pay")->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('test', $order->payment_gateway);
        $this->assertTrue($user->hasAccessToDeck($this->deck));
        Queue::assertPushed(SendTransactionalEmail::class, fn ($j) => $j->type === BrevoEmailService::ORDER_CONFIRMATION);
        Queue::assertPushed(SendMetaConversionEvent::class, fn ($j) => $j->event['event_name'] === 'Purchase');

        // Paying twice is harmless.
        $this->postJson("/api/v1/checkout/test/{$session}/pay")->assertOk();
        $this->assertSame(1, $order->entitlements()->count());
        $this->getJson("/api/v1/checkout/status/{$session}")->assertJsonPath('data.order.unlocked_decks.0.deck_code', 'DECK-001');
    }

    public function test_guest_physical_order_must_provide_email_and_address_on_test_page(): void
    {
        $oil = Product::factory()->accessory([], 2400, stock: 3)->create();
        $token = $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id])->json('data.cart.cart_token');
        $session = $this->startCheckout(['X-Cart-Token' => $token]);

        $this->getJson("/api/v1/checkout/status/{$session}")
            ->assertJsonPath('data.order.needs_email', true)
            ->assertJsonPath('data.order.needs_shipping_address', true);

        $this->postJson("/api/v1/checkout/test/{$session}/pay")->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson("/api/v1/checkout/test/{$session}/pay", [
            'email' => 'guest@example.com',
            'shipping_address' => ['name' => 'Ava Doe', 'line1' => '1 Main St', 'city' => 'Austin', 'postal_code' => '73301', 'country' => 'US'],
        ])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('guest@example.com', $order->customer_email);
        $this->assertSame(2, $oil->fresh()->stock_quantity);
    }

    public function test_cancel_marks_order_failed(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);
        $session = $this->startCheckout();

        $this->postJson("/api/v1/checkout/test/{$session}/cancel")->assertOk();

        $this->assertSame(PaymentStatus::Failed, Order::firstOrFail()->payment_status);
        $this->postJson("/api/v1/checkout/test/{$session}/pay")->assertOk();
        $this->assertSame(PaymentStatus::Paid, Order::firstOrFail()->payment_status, 'Retrying after cancel is allowed, like a new card attempt.');
    }

    public function test_test_mode_is_unavailable_in_production_or_when_disabled(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);
        $session = $this->startCheckout();

        config(['provoke.enable_test_fulfillment' => false]);
        $this->postJson("/api/v1/checkout/test/{$session}/pay")->assertNotFound();
        $this->postJson('/api/v1/checkout/session')->assertStatus(503);

        config(['provoke.enable_test_fulfillment' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->postJson("/api/v1/checkout/test/{$session}/pay")->assertNotFound();
        $this->postJson('/api/v1/checkout/session')->assertStatus(503);

        $this->assertSame(PaymentStatus::Pending, Order::firstOrFail()->payment_status);
    }

    public function test_stripe_takes_over_once_configured_and_stripe_orders_cannot_be_test_paid(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_x']);
        $order = Order::create(['total_cents' => 1900, 'payment_gateway' => 'stripe', 'metadata' => ['checkout_session_id' => 'cs_test_realstripe12345']]);

        $this->assertInstanceOf(StripeGateway::class, app(PaymentGateway::class));
        $this->postJson('/api/v1/checkout/test/cs_test_realstripe12345/pay')->assertNotFound();
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }
}
