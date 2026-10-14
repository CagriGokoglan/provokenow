<?php

namespace App\Services\Commerce;

use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Exceptions\BusinessRuleException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Support\Tracking\TrackingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CartPricing $pricing,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Validates the cart and snapshots it into a pending order, ready for a payment gateway.
     *
     * @param  array{email?: string, shipping_address?: array, billing_address?: array, payment_gateway?: string}  $input
     */
    public function createPendingOrder(Cart $cart, ?User $user, array $input, ?TrackingContext $tracking = null, bool $gatewayCollectsDetails = false): Order
    {
        if ($cart->items->isEmpty()) {
            throw new BusinessRuleException('Your cart is empty.', 422);
        }

        $this->assertPurchasable($cart, $user);

        $priced = $this->pricing->price($cart);

        // A hosted checkout (Stripe) collects email and shipping address itself; the webhook copies them back.
        if (! $gatewayCollectsDetails && $priced['requires_shipping'] && empty($input['shipping_address'])) {
            throw ValidationException::withMessages(['shipping_address' => 'A shipping address is required for physical items.']);
        }

        $email = $user?->email ?? ($input['email'] ?? null);
        if (! $email && ! $gatewayCollectsDetails) {
            throw ValidationException::withMessages(['email' => 'An email address is required for guest checkout.']);
        }

        return DB::transaction(function () use ($cart, $user, $input, $priced, $email, $tracking) {
            $order = Order::create([
                'user_id' => $user?->getKey(),
                'customer_email' => $email,
                'subtotal_cents' => $priced['subtotal_cents'],
                'discount_cents' => $priced['discount_cents'],
                'tax_cents' => $priced['tax_cents'],
                'shipping_cents' => $priced['shipping_cents'],
                'total_cents' => $priced['total_cents'],
                'currency' => $priced['currency'],
                'payment_status' => PaymentStatus::Pending,
                'shipping_address' => $priced['requires_shipping'] ? ($input['shipping_address'] ?? null) : null,
                'billing_address' => $input['billing_address'] ?? null,
                'payment_gateway' => $input['payment_gateway'] ?? null,
                'metadata' => [
                    'cart_id' => $cart->getKey(),
                    'requires_shipping' => $priced['requires_shipping'],
                    // Browser signals for the server-side Purchase event; only kept with consent.
                    'tracking' => $tracking?->consent ? $tracking->toArray() : null,
                ],
            ]);

            foreach ($priced['lines'] as $line) {
                $product = $line['item']->product;

                $order->items()->create([
                    'product_id' => $product->getKey(),
                    'quantity' => $line['item']->quantity,
                    'unit_price_cents' => $line['unit_price_cents'],
                    'subtotal_cents' => $line['line_total_cents'],
                    'metadata' => [
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'product_type' => $product->product_type->value,
                        'list_price_cents' => $product->price_cents,
                        'applied_discount_cents' => $line['applied_discount_cents'],
                        'upsell_bundle' => $line['applied_discount_cents'] > 0,
                        'deck_ids' => $product->decks->modelKeys(),
                        'requires_shipping' => $product->requiresShipping(),
                    ],
                ]);
            }

            return $order->load('items');
        });
    }

    private function assertPurchasable(Cart $cart, ?User $user): void
    {
        $errors = [];

        foreach ($cart->items as $item) {
            $product = $item->product;
            $key = "items.{$item->id}";

            if (! $product->is_active) {
                $errors[$key][] = "{$product->name} is no longer available.";
            } elseif (! $product->hasStockFor($item->quantity)) {
                $errors[$key][] = "Only {$product->stock_quantity} of {$product->name} left in stock.";
            }

            if ($product->decks->isNotEmpty()) {
                if (! $user) {
                    throw new BusinessRuleException('Please sign in to purchase products that include digital deck access.', 401);
                }

                if ($product->product_type === ProductType::DigitalDeck && $this->entitlements->userOwnsAllDecksOf($user, $product)) {
                    $errors[$key][] = "You already own {$product->name}.";
                }
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException('Some items in your cart cannot be purchased.', 409, $errors);
        }
    }
}
