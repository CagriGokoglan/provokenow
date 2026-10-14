<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutIntentRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\SendMetaConversionEvent;
use App\Services\Commerce\CartResolver;
use App\Services\Commerce\CheckoutService;
use App\Services\Marketing\MetaCapiService;
use App\Support\ApiResponse;
use App\Support\Tracking\TrackingContext;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function intent(CheckoutIntentRequest $request, CartResolver $carts, CheckoutService $checkout, MetaCapiService $meta): JsonResponse
    {
        $cart = $carts->resolve($request) ?? throw new BusinessRuleException('Your cart is empty.', 422);

        $tracking = TrackingContext::fromRequest($request);
        $order = $checkout->createPendingOrder($cart, $request->user('sanctum'), $request->validated(), $tracking);

        if ($event = $meta->initiateCheckoutEvent($order, $tracking)) {
            SendMetaConversionEvent::dispatch($event);
        }

        return ApiResponse::success([
            'order' => new OrderResource($order),
            // Pass these as eventID to the browser Pixel so Meta de-duplicates browser + server events.
            'tracking' => [
                'initiate_checkout_event_id' => "checkout.{$order->id}",
                'purchase_event_id' => "purchase.{$order->id}",
            ],
        ], 'Order created. Awaiting payment.', 201);
    }
}
