<?php

namespace App\Observers;

use App\Enums\FulfillmentStatus;
use App\Jobs\SendTransactionalEmail;
use App\Models\Order;
use App\Services\Marketing\BrevoEmailService;

class OrderObserver
{
    public function updated(Order $order): void
    {
        $status = $order->fulfillment_status;

        // Digital-only orders jump straight to "delivered" at payment; they get the confirmation email only.
        if ($order->wasChanged('fulfillment_status')
            && in_array($status, [FulfillmentStatus::Shipped, FulfillmentStatus::Delivered], true)
            && ($order->metadata['requires_shipping'] ?? false)) {
            SendTransactionalEmail::dispatch($order->getKey(), BrevoEmailService::SHIPPING_UPDATE, $status->value);
        }
    }
}
