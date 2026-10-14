<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutIntentRequest;
use App\Http\Resources\OrderResource;
use App\Services\Commerce\CartResolver;
use App\Services\Commerce\CheckoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function intent(CheckoutIntentRequest $request, CartResolver $carts, CheckoutService $checkout): JsonResponse
    {
        $cart = $carts->resolve($request) ?? throw new BusinessRuleException('Your cart is empty.', 422);

        $order = $checkout->createPendingOrder($cart, $request->user('sanctum'), $request->validated());

        return ApiResponse::success(['order' => new OrderResource($order)], 'Order created. Awaiting payment.', 201);
    }
}
