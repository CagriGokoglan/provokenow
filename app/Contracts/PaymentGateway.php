<?php

namespace App\Contracts;

use App\Models\Order;
use App\Services\Payments\CheckoutRedirect;

/**
 * A hosted-checkout payment provider. Implementations redirect the customer to the provider
 * and confirm payment via a signed webhook that calls OrderFulfillmentService — never via the
 * browser redirect.
 */
interface PaymentGateway
{
    /** Provider key stored on orders.payment_gateway (e.g. "stripe"). */
    public function name(): string;

    public function isConfigured(): bool;

    /** Create a hosted checkout for a pending order. */
    public function createCheckout(Order $order): CheckoutRedirect;
}
