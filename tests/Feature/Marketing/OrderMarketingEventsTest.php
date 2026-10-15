<?php

namespace Tests\Feature\Marketing;

use App\Enums\FulfillmentStatus;
use App\Enums\UserRole;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTransactionalEmail;
use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketing\BrevoEmailService;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderMarketingEventsTest extends TestCase
{
    use RefreshDatabase;

    private const CONSENT = [
        'X-Tracking-Consent' => 'granted',
        'X-Fbp' => 'fb.1.1700000000000.123456789',
        'X-Page-Url' => 'https://provokenow.com/checkout',
        'User-Agent' => 'Mozilla/5.0 (iPhone)',
    ];

    private User $customer;

    private User $admin;

    private Deck $deck;

    private Product $digital;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.pixel_id' => '999000111',
            'services.meta.capi_access_token' => 'capi-token',
            'services.meta.graph_version' => 'v19.0',
            'services.brevo.key' => 'brevo-key',
        ]);

        $this->customer = User::factory()->create(['email' => 'Ava.Doe@Example.com']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin])->save();
        $this->deck = Deck::factory()->create(['deck_code' => 'DECK-010', 'title' => 'Slow Burn']);
        $this->digital = Product::factory()->digitalDeck($this->deck, 1900)->create(['sku' => 'DIG-010']);
    }

    /** Runs cart → checkout → fulfil-test and returns the order id. */
    private function purchase(array $headers = [], array $checkout = []): string
    {
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id], $headers)->assertOk();
        $orderId = $this->postJson('/api/v1/checkout/intent', $checkout, $headers)->assertCreated()->json('data.order.id');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $orderId])->assertOk();

        return $orderId;
    }

    public function test_fulfillment_queues_purchase_capi_event_with_hashed_user_data_and_confirmation_email(): void
    {
        Queue::fake();

        $orderId = $this->purchase(self::CONSENT);

        Queue::assertPushed(SendMetaConversionEvent::class, fn ($job) => $job->event['event_name'] === 'AddToCart');
        Queue::assertPushed(SendMetaConversionEvent::class, fn ($job) => $job->event['event_name'] === 'InitiateCheckout'
            && $job->event['event_id'] === "checkout.{$orderId}");

        Queue::assertPushed(SendMetaConversionEvent::class, function (SendMetaConversionEvent $job) use ($orderId) {
            $e = $job->event;
            if ($e['event_name'] !== 'Purchase') {
                return false;
            }

            $this->assertSame("purchase.{$orderId}", $e['event_id']);
            $this->assertSame('website', $e['action_source']);
            $this->assertSame('https://provokenow.com/checkout', $e['event_source_url']);
            // Hashed, normalised identifiers…
            $this->assertSame([hash('sha256', 'ava.doe@example.com')], $e['user_data']['em']);
            $this->assertSame([hash('sha256', strtolower($this->customer->id))], $e['user_data']['external_id']);
            // …while IP / UA / fbp stay raw, as Meta requires.
            $this->assertSame('127.0.0.1', $e['user_data']['client_ip_address']);
            $this->assertSame('Mozilla/5.0 (iPhone)', $e['user_data']['client_user_agent']);
            $this->assertSame('fb.1.1700000000000.123456789', $e['user_data']['fbp']);
            $this->assertSame(19.0, $e['custom_data']['value']);
            $this->assertSame('USD', $e['custom_data']['currency']);
            $this->assertSame(['DIG-010'], $e['custom_data']['content_ids']);
            // No raw PII or product names in the queued payload.
            $serialized = json_encode($e);
            $this->assertStringNotContainsStringIgnoringCase('ava.doe@example.com', $serialized);
            $this->assertStringNotContainsString('Slow Burn', $serialized);

            return true;
        });

        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->orderId === $orderId
            && $job->type === BrevoEmailService::ORDER_CONFIRMATION);
        Queue::assertNotPushed(SendTransactionalEmail::class, fn ($job) => $job->type === BrevoEmailService::SHIPPING_UPDATE);
    }

    public function test_no_meta_events_without_consent_but_email_still_sent(): void
    {
        Queue::fake();

        $orderId = $this->purchase();

        Queue::assertNotPushed(SendMetaConversionEvent::class);
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->orderId === $orderId);
        $this->assertNull(Order::find($orderId)->metadata['tracking']);
    }

    public function test_repeated_fulfillment_does_not_requeue_purchase_or_email(): void
    {
        Queue::fake();

        $orderId = $this->purchase(self::CONSENT);
        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $orderId])->assertOk();

        Queue::assertPushed(SendMetaConversionEvent::class, fn ($job) => $job->event['event_name'] === 'Purchase', 1);
        Queue::assertPushed(SendTransactionalEmail::class, 1);
    }

    public function test_shipping_status_change_queues_shipping_update_for_physical_orders(): void
    {
        Queue::fake();
        $oil = Product::factory()->accessory([], 2400)->create();

        Sanctum::actingAs($this->customer);
        $this->postJson('/api/v1/cart/add', ['product_id' => $oil->id]);
        $orderId = $this->postJson('/api/v1/checkout/intent', ['shipping_address' => [
            'name' => 'Ava Doe', 'line1' => '1 Main St', 'city' => 'Austin', 'postal_code' => '73301', 'country' => 'US',
        ]])->json('data.order.id');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders/fulfill-test', ['order_id' => $orderId])->assertOk();

        $order = Order::find($orderId);
        $order->update(['fulfillment_status' => FulfillmentStatus::Shipped]);

        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->type === BrevoEmailService::SHIPPING_UPDATE
            && $job->status === 'shipped' && $job->orderId === $orderId);
    }

    public function test_capi_job_posts_to_graph_api_and_brevo_job_sends_once(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1]),
            'api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], 201),
        ]);

        Queue::fake();
        $orderId = $this->purchase(self::CONSENT);

        // Execute the queued jobs for real against the faked HTTP layer.
        $purchaseJob = Queue::pushed(SendMetaConversionEvent::class, fn ($j) => $j->event['event_name'] === 'Purchase')->first();
        $purchaseJob->handle(app(MetaCapiService::class));

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v19.0/999000111/events')
            && str_contains($r->url(), 'access_token=capi-token')
            && $r['data'][0]['event_name'] === 'Purchase');

        $emailJob = new SendTransactionalEmail($orderId, BrevoEmailService::ORDER_CONFIRMATION);
        $emailJob->handle(app(BrevoEmailService::class));
        $emailJob->handle(app(BrevoEmailService::class)); // retry after success → no second send

        $brevoCalls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.brevo.com'));
        $this->assertCount(1, $brevoCalls);

        [$request] = $brevoCalls->first();
        $order = Order::find($orderId);
        $this->assertSame('https://api.brevo.com/v3/smtp/email', $request->url());
        $this->assertSame('brevo-key', $request->header('api-key')[0]);
        $this->assertSame('Ava.Doe@Example.com', $request['to'][0]['email']);
        $this->assertSame("Your PROVOKE NOW order {$order->order_number} is confirmed", $request['subject']);
        $this->assertStringContainsString('https://provokenow.com/decks/deck-010/play', $request['htmlContent']);
        $this->assertArrayHasKey("order_confirmation:{$orderId}", $order->metadata['emails_sent']);
    }

    public function test_services_are_inert_without_credentials(): void
    {
        config(['services.meta.capi_access_token' => null, 'services.brevo.key' => null]);
        Http::fake();
        Queue::fake();

        $orderId = $this->purchase(self::CONSENT);
        Queue::assertNotPushed(SendMetaConversionEvent::class);

        (new SendTransactionalEmail($orderId, BrevoEmailService::ORDER_CONFIRMATION))->handle(app(BrevoEmailService::class));
        Http::assertNothingSent();
    }
}
