<?php

namespace Tests\Feature\Catalog;

use App\Enums\DeckSegment;
use App\Models\Deck;
use App\Models\Product;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdultTierAccessTest extends TestCase
{
    use RefreshDatabase;

    private function verifyPath(User $user): string
    {
        parse_str((string) parse_url(FrontendUrl::verifyEmail($user), PHP_URL_QUERY), $q);

        return "/api/v1/auth/email/verify/{$q['id']}/{$q['hash']}?".http_build_query(['expires' => $q['expires'], 'signature' => $q['signature']]);
    }

    public function test_verifying_the_email_saves_the_passion_mode_choice(): void
    {
        $off = User::factory()->unverified()->create();
        $this->postJson($this->verifyPath($off), ['adult_unlocked' => false])->assertOk()->assertJsonPath('data.adult_unlocked', false);
        $this->assertTrue($off->fresh()->hasVerifiedEmail());
        $this->assertFalse($off->fresh()->hasAdultAccess());

        $on = User::factory()->unverified()->create();
        $path = $this->verifyPath($on);
        $this->postJson($path, ['adult_unlocked' => true])->assertOk()->assertJsonPath('data.adult_unlocked', true);
        $this->assertTrue($on->fresh()->hasAdultAccess());
        $this->assertNotNull($on->fresh()->adult_unlocked_at);

        // The same valid link can change the choice again; a forged one cannot.
        $this->postJson($path, ['adult_unlocked' => false])->assertJsonPath('data.already', true)->assertJsonPath('data.adult_unlocked', false);
        $forged = preg_replace('/signature=[^&]+/', 'signature=forged', $path);
        $this->postJson($forged, ['adult_unlocked' => true])->assertJsonPath('data.adult_unlocked', false);
    }

    public function test_homepage_cards_are_face_down_until_the_visitor_is_verified(): void
    {
        Deck::factory()->segment(DeckSegment::LightIgnition, 1)->withCards(10)->create();
        Deck::factory()->segment(DeckSegment::Taboo, 5)->create(['deck_code' => 'DECK-099']);

        $guest = $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.cards_revealed', false);
        $this->assertNotEmpty($guest->json('data.showcase_cards'));
        $this->assertTrue(collect($guest->json('data.showcase_cards'))->every(fn ($c) => $c['card']['content'] === null && $c['card']['title'] === null));
        $this->assertNotContains('DECK-099', collect($guest->json('data.showcase_decks'))->pluck('deck_code'));

        Sanctum::actingAs(User::factory()->create());
        $member = $this->withToken('member-token')->getJson('/api/v1/home')->assertJsonPath('data.cards_revealed', true);
        $this->assertNotNull($member->json('data.showcase_cards.0.card.content'));
    }

    public function test_products_of_advanced_and_taboo_decks_are_hidden_and_cannot_be_bought_without_passion_mode(): void
    {
        $taboo = Deck::factory()->segment(DeckSegment::Taboo, 5)->create();
        $box = Product::factory()->podDeck($taboo, 4500)->create(['slug' => 'taboo-box']);
        Product::factory()->accessory()->create(['slug' => 'silk-blindfold']);

        $slugs = fn () => collect($this->getJson('/api/v1/products')->json('data.items'))->pluck('slug')->all();
        $this->assertSame(['silk-blindfold'], $slugs());
        $this->getJson('/api/v1/products/taboo-box')->assertNotFound();
        $this->postJson('/api/v1/cart/add', ['product_id' => $box->id])->assertForbidden()->assertJsonPath('errors.reason.0', 'adult_locked');

        Sanctum::actingAs(User::factory()->adult()->create());
        $this->withToken('member-token');
        $this->assertEqualsCanonicalizing(['silk-blindfold', 'taboo-box'], $slugs());
        $this->getJson('/api/v1/products/taboo-box')->assertOk();
        $this->postJson('/api/v1/cart/add', ['product_id' => $box->id])->assertOk();
    }
}
