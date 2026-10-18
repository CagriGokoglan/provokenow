<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Filament\Resources\DeckResource\Pages\EditDeck;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Deck;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Digital editions live on the deck; the store admin holds physical inventory only. */
class CatalogEditionsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin);
    }

    public function test_the_digital_edition_is_priced_and_switched_on_from_the_deck(): void
    {
        $deck = Deck::factory()->create(['deck_code' => 'DECK-007', 'title' => 'Velvet Rope']);
        $this->assertNull($deck->digitalProduct, 'No price, no digital product.');

        Livewire::test(EditDeck::class, ['record' => $deck->getRouteKey()])
            ->fillForm(['is_digital_sale_active' => true, 'digital_price_cents' => '12.99', 'digital_sku' => 'DIG-VR-01'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product = $deck->fresh()->digitalProduct;
        $this->assertSame([ProductType::DigitalDeck, 1299, 'DIG-VR-01', true, false], [
            $product->product_type, $product->price_cents, $product->sku, $product->is_active, $product->requiresShipping(),
        ]);
        $this->assertSame('Velvet Rope — Digital Deck', $product->name);
        $this->assertSame([$deck->id], $product->decks()->pluck('decks.id')->all(), 'Buying it unlocks the deck.');

        // A price change applies immediately; switching sales off deactivates it.
        Livewire::test(EditDeck::class, ['record' => $deck->getRouteKey()])->fillForm(['digital_price_cents' => '9.99'])->call('save');
        $this->assertSame(999, $product->fresh()->price_cents);
        Livewire::test(EditDeck::class, ['record' => $deck->getRouteKey()])->fillForm(['is_digital_sale_active' => false])->call('save');
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(1, Product::count(), 'Edited in place, never duplicated.');

        // Never listed in the store or its admin.
        $this->getJson('/api/v1/products')->assertJsonPath('data.pagination.total', 0);
        Livewire::test(ListProducts::class)->assertCanNotSeeTableRecords([$product]);
    }

    public function test_a_physical_boxed_game_links_one_deck_that_has_no_box_yet(): void
    {
        $free = Deck::factory()->create(['deck_code' => 'DECK-001']);
        $boxed = Deck::factory()->create(['deck_code' => 'DECK-002']);
        Product::factory()->podDeck($boxed)->create();

        $form = Livewire::test(CreateProduct::class)->fillForm(['product_type' => ProductType::PodPhysicalDeck->value]);
        $options = $form->instance()->form->getComponent('data.linked_deck_id')->getOptions();
        $this->assertArrayHasKey($free->id, $options);
        $this->assertArrayNotHasKey($boxed->id, $options, 'Already has a physical box.');

        $form->fillForm([
            'name' => 'First Light — The Box', 'slug' => 'first-light-box', 'sku' => 'BOX-001',
            'product_type' => ProductType::PodPhysicalDeck->value, 'linked_deck_id' => $free->id,
            'price_cents' => '49.99', 'stock_quantity' => -1, 'requires_shipping' => true,
        ])->call('create')->assertHasNoFormErrors();

        $box = Product::firstWhere('slug', 'first-light-box');
        $this->assertSame($free->id, $box->linked_deck_id);
        $this->assertSame([$free->id], $box->decks()->pluck('decks.id')->all(), 'Buyers get the deck digitally.');
        $this->assertTrue($box->requiresShipping());
        $this->assertSame($box->id, $free->fresh()->physicalProduct->id);

        // A physical deck must name its deck.
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Orphan box', 'slug' => 'orphan', 'sku' => 'BOX-X', 'product_type' => ProductType::PodPhysicalDeck->value, 'price_cents' => '10'])
            ->call('create')
            ->assertHasFormErrors(['linked_deck_id']);
    }

    public function test_store_product_types_exclude_digital_decks(): void
    {
        $options = Livewire::test(CreateProduct::class)->instance()->form->getComponent('data.product_type')->getOptions();

        $this->assertSame([ProductType::PodPhysicalDeck->value, ProductType::PhysicalAccessory->value, ProductType::Bundle->value], array_keys($options));
        $this->assertSame('Physical Boxed Game Set', $options[ProductType::PodPhysicalDeck->value]);
    }
}
