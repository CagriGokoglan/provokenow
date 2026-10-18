<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplimentaryShippingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_is_free_on_any_cart_and_labelled(): void
    {
        $cheap = Product::factory()->accessory([], 500)->create();
        $add = $this->postJson('/api/v1/cart/add', ['product_id' => $cheap->id])->assertOk();
        $token = $add->json('data.cart.cart_token');

        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])
            ->assertJsonPath('data.cart.requires_shipping', true)
            ->assertJsonPath('data.cart.totals.shipping_cents', 0)
            ->assertJsonPath('data.cart.totals.shipping_label', 'Complimentary Discreet Express Shipping')
            ->assertJsonPath('data.cart.totals.total_cents', 500);
    }

    public function test_the_badge_text_is_set_in_the_admin_with_a_default(): void
    {
        $this->getJson('/api/v1/config/public')
            ->assertJsonPath('data.shipping.badge_text', 'Complimentary Worldwide Express Shipping in 100% Unbranded Black Packaging')
            ->assertJsonPath('data.shipping.label', 'Complimentary Discreet Express Shipping');

        SiteSetting::put('discreet_shipping_badge_text', 'Ships free, plainly packed.');
        $this->getJson('/api/v1/config/public')->assertJsonPath('data.shipping.badge_text', 'Ships free, plainly packed.');
    }
}
