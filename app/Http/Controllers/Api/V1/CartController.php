<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\ApplyUpsellRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\UpsellOfferResource;
use App\Jobs\SendMetaConversionEvent;
use App\Models\Cart;
use App\Models\Product;
use App\Services\Commerce\CartPricing;
use App\Services\Commerce\CartResolver;
use App\Services\Commerce\CartService;
use App\Services\Commerce\UpsellEngine;
use App\Services\Marketing\MetaCapiService;
use App\Support\ApiResponse;
use App\Support\Tracking\TrackingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Works for guests (X-Cart-Token) and signed-in users (Bearer token). */
class CartController extends Controller
{
    public function __construct(
        private readonly CartResolver $carts,
        private readonly CartService $service,
        private readonly CartPricing $pricing,
        private readonly UpsellEngine $upsell,
        private readonly MetaCapiService $meta,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->carts->resolve($request);

        if (! $cart) {
            return ApiResponse::success(['cart' => null], 'Cart is empty.');
        }

        return ApiResponse::success(['cart' => $this->present($cart)], 'Cart retrieved.');
    }

    public function add(AddToCartRequest $request): JsonResponse
    {
        $product = Product::findOrFail($request->validated('product_id'));
        if ($product->isAdultTier() && ! $request->user('sanctum')?->hasAdultAccess()) {
            return ApiResponse::error('This item is for verified members with Passion mode (18+) switched on.', 403, ['reason' => [DeckController::LOCK_ADULT]]);
        }
        $cart = $this->carts->resolve($request, create: true);

        $quantity = (int) $request->validated('quantity', 1);
        $this->service->add($cart, $product, $quantity, $request->user('sanctum'));

        $eventId = 'atc.'.Str::uuid7();
        $event = $this->meta->addToCartEvent($product, $quantity, $eventId, $request->user('sanctum'), TrackingContext::fromRequest($request));
        if ($event) {
            SendMetaConversionEvent::dispatch($event);
        }

        return ApiResponse::success([
            'cart' => $this->present($cart->refresh()),
            'tracking' => ['add_to_cart_event_id' => $eventId],
        ], 'Item added to cart.');
    }

    public function applyUpsell(ApplyUpsellRequest $request): JsonResponse
    {
        $cart = $this->carts->resolve($request);

        if (! $cart || $cart->items->isEmpty()) {
            return ApiResponse::success(['cart' => null, 'upsell_offers' => []], 'Cart is empty.');
        }

        $message = 'Upsell offers evaluated.';

        if ($productId = $request->validated('product_id')) {
            $product = Product::with('decks')->findOrFail($productId);
            $this->service->applyUpsell($cart, $product, $request->user('sanctum'), $request->validated('anchor_item_id'));
            $cart->refresh();
            $message = 'Upsell added with bundle discount.';
        }

        return ApiResponse::success([
            'cart' => $this->present($cart),
            'upsell_offers' => UpsellOfferResource::collection($this->upsell->suggestionsForCart($cart)),
        ], $message);
    }

    public function remove(Request $request, string $itemId): JsonResponse
    {
        $cart = $this->carts->resolve($request);

        if (! $cart) {
            return ApiResponse::error('Cart item not found.', 404);
        }

        $this->service->remove($cart, $itemId);

        return ApiResponse::success(['cart' => $this->present($cart->refresh())], 'Item removed from cart.');
    }

    private function present(Cart $cart): CartResource
    {
        $cart->loadMissing('items.product.decks', 'items.product.upsellRules');

        return new CartResource(['cart' => $cart, 'priced' => $this->pricing->price($cart)]);
    }
}
