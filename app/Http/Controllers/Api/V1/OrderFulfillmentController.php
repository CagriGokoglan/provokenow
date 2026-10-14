<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Commerce\OrderFulfillmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Test-only stand-in for a payment gateway webhook. The route exists only when
 * provoke.enable_test_fulfillment is true, and only staff can call it.
 */
class OrderFulfillmentController extends Controller
{
    public function fulfillTest(Request $request, OrderFulfillmentService $fulfillment): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required_without:order_number', 'uuid'],
            'order_number' => ['required_without:order_id', 'string', 'max:32'],
            'transaction_id' => ['sometimes', 'string', 'max:255'],
        ]);

        $order = Order::query()
            ->when($data['order_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['order_number'] ?? null, fn ($q, $number) => $q->where('order_number', $number))
            ->firstOrFail();

        $order = $fulfillment->markPaid($order, 'test', $data['transaction_id'] ?? 'test_'.Str::random(24));

        return ApiResponse::success(['order' => new OrderResource($order)], 'Payment confirmed. Order fulfilled.');
    }
}
