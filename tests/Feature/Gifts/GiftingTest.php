<?php

namespace Tests\Feature\Gifts;

use App\Enums\DeckSegment;
use App\Enums\GiftStatus;
use App\Jobs\SendTransactionalEmail;
use App\Mail\GiftReceivedMailable;
use App\Mail\OrderReceiptMailable;
use App\Models\Deck;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Commerce\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GiftingTest extends TestCase
{
    use RefreshDatabase;

    private Deck $deck;

    private Product $digital;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.secret' => null,
            'provoke.enable_test_fulfillment' => true,
            'provoke.frontend.url' => 'http://localhost:3000',
        ]);
        Mail::fake();
        Queue::fake();

        $this->deck = Deck::factory()->withCards(3)->create(['deck_code' => 'DECK-001', 'title' => 'Velvet Hours']);
        $this->digital = Product::factory()->digitalDeck($this->deck, 1900)->create();
        $this->sender = User::factory()->create(['email' => 'sender@example.com']);
    }

    /** Buys a gift as the sender and pays it in test mode. Returns the gift. */
    private function buyGift(array $details, ?Product $product = null, array $payDetails = []): Gift
    {
        Sanctum::actingAs($this->sender);
        $url = $this->postJson('/api/v1/checkout/gift', ['product_id' => ($product ?? $this->digital)->id, ...$details])->assertCreated()->json('data.checkout_url');
        $session = substr($url, strpos($url, 'session_id=') + 11);
        $this->postJson("/api/v1/checkout/test/{$session}/pay", $payDetails)->assertOk();

        return Gift::whereHas('order', fn ($q) => $q->where('metadata->checkout_session_id', $session))->firstOrFail();
    }

    private function tokenFrom(Gift $gift): string
    {
        $token = null;
        Mail::assertQueued(GiftReceivedMailable::class, function (GiftReceivedMailable $m) use ($gift, &$token) {
            if ($m->gift->is($gift)) {
                $token = $m->claimToken;
            }

            return true;
        });

        return (string) $token;
    }

    public function test_gift_to_a_registered_member_is_claimed_with_an_18_plus_confirmation(): void
    {
        $recipient = User::factory()->create(['email' => 'partner@example.com']);

        $gift = $this->buyGift(['recipient_email' => 'Partner@Example.com', 'sender_alias' => 'Your Partner', 'gift_message' => 'For tonight.']);

        // Paid: the buyer did not get the deck; the recipient was emailed.
        $this->assertSame(GiftStatus::Sent, $gift->status);
        $this->assertSame($recipient->id, $gift->recipient_id);
        $this->assertFalse($this->sender->hasAccessToDeck($this->deck));
        Mail::assertQueued(GiftReceivedMailable::class, fn ($m) => $m->hasTo('partner@example.com'));
        Queue::assertPushed(SendTransactionalEmail::class, fn ($j) => $j->type === SendTransactionalEmail::ORDER_CONFIRMATION);
        $receipt = (new OrderReceiptMailable($gift->order->fresh()))->render();
        $this->assertStringContainsString('Your gift is confirmed', $receipt);
        $this->assertStringContainsString('partner@example.com', $receipt);
        $html = (new GiftReceivedMailable($gift->fresh(['order.items', 'recipient']), 'tok'))->render();
        $this->assertStringContainsString('Your Partner', $html);
        $this->assertStringContainsString('Log in to reveal your card in your Vault', $html);
        $this->assertStringNotContainsString('19.00', $html, 'No price in the gift email.');

        // The recipient sees it waiting, confirms 18+ and claims it into their Vault.
        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.unclaimed_gifts', 1);
        $this->getJson('/api/v1/user/gifts/pending')->assertJsonPath('data.gifts.0.from', 'Your Partner');
        $this->postJson("/api/v1/user/gifts/{$gift->id}/claim")->assertUnprocessable()->assertJsonValidationErrors('confirm_age');
        $this->postJson("/api/v1/user/gifts/{$gift->id}/claim", ['confirm_age' => true])->assertOk()->assertJsonPath('data.gift.status', 'claimed');

        $this->assertTrue($recipient->hasAccessToDeck($this->deck));
        $this->assertNotNull($recipient->fresh()->age_confirmed_at);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.unclaimed_gifts', 0);
        $this->getJson('/api/v1/user/entitlements')
            ->assertJsonPath('data.entitlements.0.acquired_via', 'gift')
            ->assertJsonPath('data.entitlements.0.gift.from', 'Your Partner')
            ->assertJsonPath('data.entitlements.0.gift.message', 'For tonight.');

        // Claiming twice is harmless; nobody else can claim it.
        $this->postJson("/api/v1/user/gifts/{$gift->id}/claim", ['confirm_age' => true])->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/user/gifts/{$gift->id}/claim", ['confirm_age' => true])->assertForbidden();
    }

    public function test_an_invitation_lets_a_new_person_register_and_claim(): void
    {
        $gift = $this->buyGift(['recipient_email' => 'new@example.com']);
        $token = $this->tokenFrom($gift);
        $this->assertNull($gift->recipient_id);
        $this->assertStringContainsString('Register &amp; Claim My Gift', (new GiftReceivedMailable($gift->fresh(['order.items', 'recipient']), $token))->render());

        // The link page knows only who it's from.
        $this->getJson("/api/v1/gifts/claim/{$token}")->assertOk()
            ->assertJsonPath('data.from', 'Someone special')
            ->assertJsonPath('data.claimable', true)
            ->assertJsonPath('data.recipient_has_account', false)
            ->assertJsonMissingPath('data.message');
        $this->getJson('/api/v1/gifts/claim/'.str_repeat('x', 48))->assertNotFound();

        // Registering from the link with the same address: verified at once, gift waiting.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Person', 'email' => 'new@example.com', 'password' => 'Secret-Pass-12', 'password_confirmation' => 'Secret-Pass-12', 'claim_token' => $token,
        ])->assertCreated();
        $user = User::firstWhere('email', 'new@example.com');
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame($user->id, $gift->fresh()->recipient_id);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/user/gifts/{$gift->id}/claim", ['confirm_age' => true])->assertOk();
        $this->assertTrue($user->hasAccessToDeck($this->deck));
        $this->getJson("/api/v1/gifts/claim/{$token}")->assertNotFound(); // the link is spent
    }

    public function test_a_claim_link_opened_by_the_wrong_account_is_refused(): void
    {
        User::factory()->create(['email' => 'partner@example.com']);
        $gift = $this->buyGift(['recipient_email' => 'partner@example.com']);
        $token = $this->tokenFrom($gift);

        Sanctum::actingAs(User::factory()->create(['email' => 'someone@example.com']));
        $this->postJson('/api/v1/user/gifts/attach', ['token' => $token])->assertForbidden();
    }

    public function test_scheduled_gifts_wait_for_their_day(): void
    {
        $this->travelTo(now('America/New_York')->setTime(12, 0));
        $gift = $this->buyGift(['recipient_email' => 'later@example.com', 'deliver_on' => now('America/New_York')->addDays(3)->toDateString()]);

        $this->assertSame(GiftStatus::Pending, $gift->status);
        Mail::assertNotQueued(GiftReceivedMailable::class);
        $this->artisan('gifts:deliver')->expectsOutputToContain('0 gift(s) sent')->assertSuccessful();

        $this->travel(3)->days();
        $this->artisan('gifts:deliver')->expectsOutputToContain('1 gift(s) sent')->assertSuccessful();
        $this->assertSame(GiftStatus::Sent, $gift->fresh()->status);
        Mail::assertQueued(GiftReceivedMailable::class, fn ($m) => $m->hasTo('later@example.com'));
    }

    public function test_unclaimed_gifts_expire_and_refunds_cancel_them(): void
    {
        $gift = $this->buyGift(['recipient_email' => 'slow@example.com']);
        $this->travel(Gift::CLAIM_DAYS + 1)->days();
        $this->artisan('gifts:deliver')->expectsOutputToContain('1 expired')->assertSuccessful();
        $this->assertSame(GiftStatus::Expired, $gift->fresh()->status);

        $this->travelBack();
        $other = $this->buyGift(['recipient_email' => 'refund@example.com']);
        app(OrderFulfillmentService::class)->recordRefund($other->order, $other->order->total_cents, full: true);
        $this->assertSame(GiftStatus::Cancelled, $other->fresh()->status);
    }

    public function test_physical_gifts_ship_to_the_checkout_address_without_a_claim(): void
    {
        $blindfold = Product::factory()->accessory()->create(['name' => 'Silk Blindfold']);

        $gift = $this->buyGift(
            ['recipient_email' => 'parcel@example.com', 'gift_message' => 'Wear it tonight.'],
            $blindfold,
            ['shipping_address' => ['name' => 'Recipient', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US']],
        );

        $this->assertSame(GiftStatus::Sent, $gift->status);
        $this->assertNull($gift->claim_token_hash);
        $this->assertSame('Austin', $gift->order->fresh()->shipping_address['city']);
        $html = (new GiftReceivedMailable($gift->fresh(['order.items', 'recipient']), null))->render();
        $this->assertStringContainsString('plain, discreet packaging', $html);
        $this->assertStringNotContainsString('Claim', $html);
    }

    public function test_gift_checkout_rules(): void
    {
        $this->postJson('/api/v1/checkout/gift', ['product_id' => $this->digital->id, 'recipient_email' => 'a@example.com'])->assertUnauthorized();

        Sanctum::actingAs($this->sender);
        $this->postJson('/api/v1/checkout/gift', ['product_id' => $this->digital->id, 'recipient_email' => 'sender@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_email');
        $this->postJson('/api/v1/checkout/gift', ['product_id' => $this->digital->id, 'recipient_email' => 'a@example.com', 'deliver_on' => '2001-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('deliver_on');

        // Advanced / Taboo items need the sender's Passion mode.
        $taboo = Product::factory()->digitalDeck(Deck::factory()->segment(DeckSegment::Taboo, 5)->create(), 2500)->create();
        $this->postJson('/api/v1/checkout/gift', ['product_id' => $taboo->id, 'recipient_email' => 'a@example.com'])->assertForbidden();
        $this->assertSame(0, Order::count());
    }
}
