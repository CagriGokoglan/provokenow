<?php

namespace Tests\Feature\Commerce;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\EntitlementsRelationManager;
use App\Jobs\SendTransactionalEmail;
use App\Mail\DigitalAccessGrantedMailable;
use App\Mail\OrderReceiptMailable;
use App\Mail\ShipmentNotificationMailable;
use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Commerce\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OrderEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Ava Doe', 'line1' => '1 Velvet Lane', 'city' => 'London', 'postal_code' => 'N1 9GU', 'country' => 'GB'];

    private function paidOrder(User $customer, array $products, bool $shipping): Order
    {
        $total = array_sum(array_map(fn (Product $p) => $p->price_cents, $products));
        $order = Order::create([
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
            'subtotal_cents' => $total,
            'total_cents' => $total,
            'shipping_address' => $shipping ? self::ADDRESS : null,
            'metadata' => ['requires_shipping' => $shipping],
        ]);
        foreach ($products as $product) {
            $order->items()->create([
                'product_id' => $product->id, 'quantity' => 1, 'unit_price_cents' => $product->price_cents,
                'subtotal_cents' => $product->price_cents, 'metadata' => ['name' => $product->name, 'sku' => $product->sku],
            ]);
        }

        Queue::fake();

        return app(OrderFulfillmentService::class)->markPaid($order, 'manual', 'ref_'.uniqid());
    }

    public function test_receipt_for_digital_decks_links_to_the_library_without_parcel_details(): void
    {
        $deck = Deck::factory()->create(['deck_code' => 'DECK-010', 'title' => 'After Midnight']);
        $order = $this->paidOrder(User::factory()->create(), [Product::factory()->digitalDeck($deck)->create()], shipping: false);

        $mail = new OrderReceiptMailable($order);
        $mail->assertSeeInHtml('After Midnight');
        $mail->assertSeeInHtml('https://provokenow.com/decks/after-midnight/play');
        $mail->assertSeeInHtml('Open my library');
        $mail->assertSeeInHtml('https://provokenow.com/account/library');
        $mail->assertDontSeeInHtml('Discreet packaging');
        $mail->assertDontSeeInHtml('Delivering to');
    }

    public function test_receipt_for_physical_products_shows_items_address_and_discreet_packaging(): void
    {
        $order = $this->paidOrder(User::factory()->create(), [Product::factory()->accessory()->create(['name' => 'Silk Blindfold'])], shipping: true);

        $mail = new OrderReceiptMailable($order);
        $mail->assertSeeInHtml('Silk Blindfold');
        $mail->assertSeeInHtml('1 Velvet Lane');
        $mail->assertSeeInHtml('N1 9GU London');
        $mail->assertSeeInHtml('Discreet packaging guaranteed');
        $mail->assertDontSeeInHtml('Open my library');
        $this->assertSame(FulfillmentStatus::Unfulfilled, $order->fulfillment_status);
    }

    public function test_mark_as_shipped_saves_tracking_and_sends_the_shipment_email_once(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin);
        $deck = Deck::factory()->create();
        $order = $this->paidOrder($customer = User::factory()->create(), [
            Product::factory()->accessory()->create(['name' => 'Silk Blindfold']),
            Product::factory()->digitalDeck($deck)->create(['name' => 'Digital Only Deck']),
        ], shipping: true);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('markShipped', data: [
                'courier_name' => 'DHL',
                'tracking_number' => ' 1234567890 ',
                'estimated_delivery_date' => now()->addDays(3)->toDateString(),
                'shipped_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(FulfillmentStatus::Shipped, $order->fulfillment_status);
        $this->assertSame(['DHL', '1234567890'], [$order->courier_name, $order->tracking_number]);
        $this->assertStringContainsString('tracking-id=1234567890', $order->tracking_url, 'Built from the courier template.');
        $this->assertNotNull($order->shipped_at);
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->type === SendTransactionalEmail::SHIPPING_UPDATE && $job->status === 'shipped');

        Mail::fake();
        $job = new SendTransactionalEmail($order->id, SendTransactionalEmail::SHIPPING_UPDATE, 'shipped');
        $job->handle();
        $job->handle();

        Mail::assertSentCount(1);
        Mail::assertSent(ShipmentNotificationMailable::class, function (ShipmentNotificationMailable $mail) use ($order) {
            $mail->assertHasSubject("Your PROVOKE NOW order {$order->order_number} is on its way");
            $mail->assertSeeInHtml('DHL');
            $mail->assertSeeInHtml('1234567890');
            $mail->assertSeeInHtml($order->tracking_url, escape: true);
            $mail->assertSeeInHtml($order->estimated_delivery_date->format('l, j F Y'));
            $mail->assertSeeInHtml('Discreet packaging guaranteed');
            $mail->assertSeeInHtml('Silk Blindfold');
            $mail->assertDontSeeInHtml('Digital Only Deck');

            return $mail->hasTo($order->customer_email);
        });
    }

    public function test_orders_can_be_cancelled_and_delivery_is_stamped(): void
    {
        $order = $this->paidOrder(User::factory()->create(), [Product::factory()->accessory()->create()], shipping: true);

        $order->update(['fulfillment_status' => FulfillmentStatus::Delivered]);
        $this->assertNotNull($order->fresh()->delivered_at);

        $other = $this->paidOrder(User::factory()->create(), [Product::factory()->accessory()->create()], shipping: true);
        $other->update(['fulfillment_status' => FulfillmentStatus::Cancelled]);
        $this->assertSame(FulfillmentStatus::Cancelled, $other->fresh()->fulfillment_status);
        Queue::assertNotPushed(SendTransactionalEmail::class, fn ($job) => $job->orderId === $other->id && $job->type === SendTransactionalEmail::SHIPPING_UPDATE);
    }

    public function test_admin_grants_email_the_customer_but_purchases_rely_on_the_receipt(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin);
        $customer = User::factory()->create();
        [$a, $b] = Deck::factory()->count(2)->create();

        Livewire::test(EntitlementsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditUser::class])
            ->callTableAction('grant', data: ['deck_ids' => [$a->id, $b->id]])
            ->assertHasNoTableActionErrors();

        Mail::assertQueued(DigitalAccessGrantedMailable::class, function (DigitalAccessGrantedMailable $mail) use ($customer, $a, $b) {
            $mail->assertSeeInHtml($a->title, escape: true);
            $mail->assertHasSubject('2 new decks are waiting in your library');

            return $mail->hasTo($customer->email) && $mail->deckIds === [$a->id, $b->id];
        });

        // Granting the same decks again adds nothing new: no second email.
        Livewire::test(EntitlementsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditUser::class])
            ->callTableAction('grant', data: ['deck_ids' => [$a->id]]);
        Mail::assertQueuedCount(1);

        $this->paidOrder(User::factory()->create(), [Product::factory()->digitalDeck(Deck::factory()->create())->create()], shipping: false);
        Mail::assertQueuedCount(1);
        $this->assertSame(PaymentStatus::Paid, Order::latest('created_at')->first()->payment_status);
    }
}
