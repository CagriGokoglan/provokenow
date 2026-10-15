<?php

namespace Tests\Feature\Admin;

use App\Enums\EntitlementStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\DeckResource\Pages\CreateDeck;
use App\Filament\Resources\DeckResource\Pages\EditDeck;
use App\Filament\Resources\DeckResource\RelationManagers\CardsRelationManager;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\EntitlementsRelationManager;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTransactionalEmail;
use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUpsell;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($this->admin);
    }

    public function test_deck_can_be_created_and_cards_bulk_imported_from_spreadsheet_and_json(): void
    {
        Livewire::test(CreateDeck::class)
            ->fillForm([
                'deck_code' => 'deck-031',
                'title' => 'Velvet Rope',
                'segment' => 'Medium_Spice',
                'intensity_level' => 3,
                'meta_title' => 'Velvet Rope — a Medium Spice deck',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $deck = Deck::firstWhere('deck_code', 'DECK-031');
        $this->assertNotNull($deck);
        $this->assertSame('Velvet Rope — a Medium Spice deck', $deck->meta_title);

        $tsv = "Card_ID\tTitle\tSetting_and_Scenario\tAction_Task\tSafety_and_Consent_Note\n"
            ."DECK-031-C01\tFirst Look\tA quiet room.\tHold eye contact for a minute.\tStop anytime.\n"
            ."DECK-031-C02\tWhisper\tLights low.\tWhisper a wish.\tCheck in first.\n";

        $manager = ['ownerRecord' => $deck, 'pageClass' => EditDeck::class];
        Livewire::test(CardsRelationManager::class, $manager)
            ->callTableAction('import', data: ['payload' => $tsv])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Cards imported');

        $this->assertSame(2, $deck->cards()->count());
        $this->assertSame('Hold eye contact for a minute.', $deck->cards()->firstWhere('card_number', 1)->content_payload['Action_Task']);

        // JSON re-import updates card 2 and adds card 3, keeping custom columns.
        $json = json_encode([
            ['Card_ID' => 'DECK-031-C02', 'Title' => 'Whisper (v2)', 'Action_Task' => 'Whisper two wishes.', 'Mood' => 'tender'],
            ['card_number' => 3, 'Title' => 'Third', 'Action_Task' => 'Trade places.'],
        ]);
        Livewire::test(CardsRelationManager::class, $manager)->callTableAction('import', data: ['payload' => $json]);

        $this->assertSame(3, $deck->cards()->count());
        $card2 = $deck->cards()->firstWhere('card_number', 2);
        $this->assertSame('Whisper (v2)', $card2->title);
        $this->assertSame('tender', $card2->content_payload['Mood']);
        $this->assertSame('DECK-031-C03', $deck->cards()->firstWhere('card_number', 3)->content_payload['Card_ID']);
    }

    public function test_deck_codes_are_not_capped_at_three_digits(): void
    {
        Livewire::test(CreateDeck::class)
            ->fillForm(['deck_code' => 'deck-1000', 'title' => 'Deck One Thousand', 'segment' => 'Light_Ignition', 'intensity_level' => 1])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(Deck::where('deck_code', 'DECK-1000')->exists());

        Livewire::test(CreateDeck::class)
            ->fillForm(['deck_code' => 'DECK-12', 'title' => 'Too short', 'segment' => 'Light_Ignition', 'intensity_level' => 1])
            ->call('create')
            ->assertHasFormErrors(['deck_code' => 'regex']);
    }

    public function test_invalid_card_import_is_rejected_without_changes(): void
    {
        $deck = Deck::factory()->create();

        Livewire::test(CardsRelationManager::class, ['ownerRecord' => $deck, 'pageClass' => EditDeck::class])
            ->callTableAction('import', data: ['payload' => json_encode([
                ['card_number' => 51, 'Title' => 'Too far'],
                ['card_number' => 2, 'Title' => ''],
            ])])
            ->assertNotified('Import failed');

        $this->assertSame(0, $deck->cards()->count());
    }

    public function test_product_form_stores_cents_and_claims_and_preserves_metadata(): void
    {
        $deck = Deck::factory()->create();
        $product = Product::factory()->accessory(['segments' => ['Medium_Spice']])->create([
            'metadata' => ['images' => [], 'upsell' => ['segments' => ['Medium_Spice']], 'supplier_ref' => 'SUP-9'],
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['price_cents' => '30.00'])
            ->fillForm(['price_cents' => '34.50', 'sale_price_cents' => '29.99', 'decks' => [$deck->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(3450, $product->price_cents);
        $this->assertSame(2999, $product->sale_price_cents);
        $this->assertSame([$deck->id], $product->decks()->pluck('decks.id')->all());
        $this->assertSame('SUP-9', $product->metadata['supplier_ref']);
        $this->assertSame(['Medium_Spice'], $product->metadata['upsell']['segments']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['sale_price_cents' => '99.00'])
            ->call('save')
            ->assertHasFormErrors(['sale_price_cents']);
    }

    public function test_curated_upsell_rule_applies_its_own_discount_in_the_cart(): void
    {
        $deck = Deck::factory()->create();
        $digital = Product::factory()->digitalDeck($deck, 2000)->create();
        $candles = Product::factory()->accessory([], 4000)->create(['name' => 'Candle Set']); // no implicit targeting
        ProductUpsell::create(['product_id' => $digital->id, 'upsell_product_id' => $candles->id, 'discount_percent' => 25]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/add', ['product_id' => $digital->id]);

        $offer = $this->postJson('/api/v1/cart/apply-upsell')->json('data.upsell_offers.0');
        $this->assertSame('curated_upsell', $offer['reason']);
        $this->assertSame(25, $offer['discount_percent']);
        $this->assertSame(1000, $offer['applied_discount_cents']);

        $cart = $this->postJson('/api/v1/cart/apply-upsell', ['product_id' => $candles->id])->json('data.cart');
        $this->assertSame(1000, $cart['totals']['discount_cents']);
    }

    public function test_mark_as_paid_and_fulfill_grants_access_and_queues_email_and_capi(): void
    {
        Queue::fake();
        $customer = User::factory()->create();
        $deck = Deck::factory()->create();
        $product = Product::factory()->digitalDeck($deck)->create();
        $order = $this->pendingOrder($customer, $product, ['tracking' => ['consent' => true, 'ip' => '203.0.113.9', 'user_agent' => 'UA']]);

        config(['services.meta.pixel_id' => '1', 'services.meta.capi_access_token' => 't']);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('markPaid')
            ->callAction('markPaid', data: ['transaction_id' => 'BANK-REF-77'])
            ->assertNotified();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('manual', $order->payment_gateway);
        $this->assertSame('BANK-REF-77', $order->transaction_id);
        $this->assertTrue($customer->hasAccessToDeck($deck));

        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->type === SendTransactionalEmail::ORDER_CONFIRMATION);
        Queue::assertPushed(SendMetaConversionEvent::class, fn ($job) => $job->event['event_name'] === 'Purchase');

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('markPaid');
    }

    public function test_updating_fulfilment_to_shipped_emails_the_customer(): void
    {
        Queue::fake();
        $oil = Product::factory()->accessory()->create();
        $order = $this->pendingOrder(null, $oil, ['requires_shipping' => true]);
        $order->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('updateFulfillment', data: [
                'fulfillment_status' => FulfillmentStatus::Shipped->value,
                'tracking_number' => '1Z999',
            ])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(FulfillmentStatus::Shipped, $order->fulfillment_status);
        $this->assertSame('1Z999', $order->tracking_number);
        $this->assertNotNull($order->shipped_at, 'Shipping time is stamped when the status changes.');
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->type === SendTransactionalEmail::SHIPPING_UPDATE && $job->status === 'shipped');
    }

    public function test_grant_and_revoke_deck_access_from_user_page(): void
    {
        $customer = User::factory()->create();
        [$a, $b] = Deck::factory()->count(2)->create();
        $manager = ['ownerRecord' => $customer, 'pageClass' => EditUser::class];

        Livewire::test(EntitlementsRelationManager::class, $manager)
            ->callTableAction('grant', data: ['deck_ids' => [$a->id, $b->id]])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($customer->hasAccessToDeck($a));
        $this->assertTrue($customer->hasAccessToDeck($b));

        $entitlement = UserEntitlement::where('user_id', $customer->id)->where('deck_id', $a->id)->first();
        Livewire::test(EntitlementsRelationManager::class, $manager)
            ->callTableAction('revoke', $entitlement);

        $this->assertSame(EntitlementStatus::Revoked, $entitlement->fresh()->status);
        $this->assertFalse($customer->hasAccessToDeck($a));
        $this->assertSame(2, UserEntitlement::where('user_id', $customer->id)->count(), 'Revoked rows are kept.');
    }

    public function test_admins_cannot_promote_users_or_edit_superadmins(): void
    {
        $customer = User::factory()->create();
        $super = User::factory()->create();
        $super->forceFill(['role' => UserRole::SuperAdmin])->save();

        $this->get("/admin/users/{$super->id}/edit")->assertForbidden();

        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['name' => 'Renamed', 'role' => UserRole::Admin->value])
            ->call('save');

        $customer->refresh();
        $this->assertSame('Renamed', $customer->name);
        $this->assertSame(UserRole::Customer, $customer->role);
    }

    public function test_superadmin_can_promote_but_not_change_own_role(): void
    {
        $this->admin->forceFill(['role' => UserRole::SuperAdmin])->save();
        $customer = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['role' => UserRole::Admin->value])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(UserRole::Admin, $customer->fresh()->role);

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->assertFormFieldIsDisabled('role');
    }

    private function pendingOrder(?User $customer, Product $product, array $metadata = []): Order
    {
        $order = Order::create([
            'user_id' => $customer?->id,
            'customer_email' => $customer?->email ?? 'guest@example.com',
            'subtotal_cents' => $product->price_cents,
            'total_cents' => $product->price_cents,
            'metadata' => $metadata,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_cents' => $product->price_cents,
            'subtotal_cents' => $product->price_cents,
            'metadata' => ['name' => $product->name, 'sku' => $product->sku],
        ]);

        return $order;
    }
}
