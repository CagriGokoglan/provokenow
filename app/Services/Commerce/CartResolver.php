<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signed-in users have one cart per account. Guests are identified by a server-issued
 * X-Cart-Token header; when a guest signs in, their token cart is merged into the account cart.
 */
class CartResolver
{
    public const HEADER = 'X-Cart-Token';

    public function resolve(Request $request, bool $create = false): ?Cart
    {
        $user = $request->user('sanctum');
        $token = $request->header(self::HEADER);
        $guestCart = is_string($token) && $token !== ''
            ? Cart::whereNull('user_id')->where('token', $token)->first()
            : null;

        if ($user) {
            $cart = ($create || $guestCart)
                ? Cart::firstOrCreate(['user_id' => $user->getKey()])
                : $user->cart;

            if ($guestCart && $cart) {
                $this->merge($guestCart, $cart);
            }

            return $cart?->load('items.product.decks', 'items.product.upsellRules');
        }

        $cart = $guestCart ?? ($create ? Cart::create(['token' => Str::random(48)]) : null);

        return $cart?->load('items.product.decks', 'items.product.upsellRules');
    }

    private function merge(Cart $from, Cart $into): void
    {
        $max = (int) config('provoke.commerce.max_line_quantity');

        DB::transaction(function () use ($from, $into, $max) {
            foreach ($from->items()->get() as $item) {
                $existing = $into->items()->where('product_id', $item->product_id)->first();

                if ($existing) {
                    $existing->update(['quantity' => min($max, $existing->quantity + $item->quantity)]);
                } else {
                    $item->update(['cart_id' => $into->getKey()]);
                }
            }

            $from->delete();
        });
    }
}
