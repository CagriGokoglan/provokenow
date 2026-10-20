<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutIntentRequest;
use App\Http\Requests\Checkout\TestCheckoutPaymentRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\SendMetaConversionEvent;
use App\Models\Order;
use App\Services\Commerce\CartResolver;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\OrderFulfillmentService;
use App\Services\Marketing\MetaCapiService;
use App\Services\Payments\TestPaymentGateway;
use App\Support\ApiResponse;
use App\Support\Tracking\TrackingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    /**
     * Creates a pending order and a hosted payment session, returning the URL to redirect to.
     * Payment is confirmed only by the provider's signed webhook, never by this request.
     */
    public function session(
        Request $request,
        CartResolver $carts,
        CheckoutService $checkout,
        PaymentGateway $gateway,
        OrderFulfillmentService $fulfillment,
        MetaCapiService $meta,
    ): JsonResponse {
        if (! $gateway->isConfigured()) {
            return ApiResponse::error('Payments are not available yet.', 503);
        }

        $cart = $carts->resolve($request) ?? throw new BusinessRuleException('Your cart is empty.', 422);
        $user = $request->user('sanctum');
        $tracking = TrackingContext::fromRequest($request);

        $order = $checkout->createPendingOrder($cart, $user, ['payment_gateway' => $gateway->name()], $tracking, gatewayCollectsDetails: true);

        try {
            $redirect = $gateway->createCheckout($order);
        } catch (Throwable $e) {
            report($e);
            $fulfillment->markFailed($order, 'gateway_session_error');

            return ApiResponse::error('We could not start the secure checkout. Please try again.', 502);
        }

        $order->update(['metadata' => [
            ...($order->metadata ?? []),
            'checkout_session_id' => $redirect->reference,
            "{$gateway->name()}_session_id" => $redirect->reference,
        ]]);

        if ($event = $meta->initiateCheckoutEvent($order, $tracking)) {
            SendMetaConversionEvent::dispatch($event);
        }

        return ApiResponse::success([
            'checkout_url' => $redirect->url,
            'order' => ['id' => $order->id, 'order_number' => $order->order_number, 'total_cents' => $order->total_cents, 'currency' => $order->currency],
            'tracking' => [
                'initiate_checkout_event_id' => "checkout.{$order->id}",
                'purchase_event_id' => "purchase.{$order->id}",
            ],
        ], 'Redirecting to secure checkout.', 201);
    }

    /**
     * Order status for the post-payment "thank you" page. The unguessable session id is the
     * capability; only non-sensitive fields are returned.
     */
    public function status(string $sessionId): JsonResponse
    {
        $order = $this->orderForSession($sessionId)->load('items', 'entitlements.deck', 'gift');

        return ApiResponse::success([
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'payment_status' => $order->payment_status->value,
                'fulfillment_status' => $order->fulfillment_status->value,
                'total_cents' => $order->total_cents,
                'currency' => $order->currency,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->metadata['name'] ?? null,
                    'sku' => $i->metadata['sku'] ?? null,
                    'quantity' => $i->quantity,
                    'subtotal_cents' => $i->subtotal_cents,
                ])->values(),
                // The buyer's own decks (a gift's decks go to its recipient).
                'unlocked_decks' => $order->entitlements->where('user_id', $order->user_id)->map(fn ($e) => [
                    'deck_code' => $e->deck->deck_code,
                    'slug' => $e->deck->slug,
                    'title' => $e->deck->title,
                ])->values(),
                'requires_shipping' => (bool) ($order->metadata['requires_shipping'] ?? false),
                'gift' => $order->gift ? [
                    'recipient_email' => $order->gift->recipient_email,
                    'scheduled_for' => $order->gift->deliver_at?->isFuture() ? $order->gift->deliver_at->toIso8601String() : null,
                ] : null,
                'payment_gateway' => $order->payment_gateway,
                // TEST MODE page: what it must collect (Stripe collects these on its own page).
                'needs_email' => $order->customer_email === null,
                'needs_shipping_address' => (bool) ($order->metadata['requires_shipping'] ?? false) && empty($order->shipping_address),
            ],
            'tracking' => ['purchase_event_id' => "purchase.{$order->id}"],
        ], 'Order status.');
    }

    /**
     * TEST MODE only: simulates a successful payment for an order created by TestPaymentGateway.
     * Runs the same fulfilment as the Stripe webhook (entitlements, stock, email, Meta CAPI).
     */
    public function testPay(TestCheckoutPaymentRequest $request, string $sessionId, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $order = $this->testOrder($sessionId);

        if (! $order->isPaid()) {
            $details = $request->validated();
            $order->update(array_filter([
                'customer_email' => $order->customer_email ?? ($details['email'] ?? null),
                'shipping_address' => $order->shipping_address ?? ($details['shipping_address'] ?? null),
            ]));

            if ($order->customer_email === null) {
                throw ValidationException::withMessages(['email' => 'An email address is required.']);
            }
            if (($order->metadata['requires_shipping'] ?? false) && empty($order->shipping_address)) {
                throw ValidationException::withMessages(['shipping_address' => 'A shipping address is required for physical items.']);
            }

            $fulfillment->markPaid($order, 'test', 'test_'.Str::lower(Str::random(24)));
        }

        return ApiResponse::success(['session_id' => $sessionId], 'Test payment completed.');
    }

    /** TEST MODE only: the customer cancelled on the test payment page. */
    public function testCancel(string $sessionId, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $fulfillment->markFailed($this->testOrder($sessionId), 'test_cancelled');

        return ApiResponse::success(null, 'Test payment cancelled.');
    }

    private function orderForSession(string $sessionId): Order
    {
        return Order::where('metadata->checkout_session_id', $sessionId)->first()
            ?? throw new BusinessRuleException('Order not found.', 404);
    }

    private function testOrder(string $sessionId): Order
    {
        // 404 (not 403) so production never reveals that this endpoint exists.
        if (! TestPaymentGateway::allowed()) {
            throw new BusinessRuleException('Not found.', 404);
        }

        $order = $this->orderForSession($sessionId);
        if ($order->payment_gateway !== 'test') {
            throw new BusinessRuleException('Order not found.', 404);
        }

        return $order;
    }
}
