<?php

namespace App\Observers;

use App\Enums\FulfillmentStatus;
use App\Jobs\SendTransactionalEmail;
use App\Models\Order;

class OrderObserver
{
    /** Shipping timestamps follow the status, whichever screen changed it. */
    public function updating(Order $order): void
    {
        if (! $order->isDirty('fulfillment_status')) {
            return;
        }

        match ($order->fulfillment_status) {
            FulfillmentStatus::Shipped => $order->shipped_at ??= now(),
            FulfillmentStatus::Delivered => [$order->shipped_at ??= now(), $order->delivered_at ??= now()],
            default => null,
        };
    }

    public function updated(Order $order): void
    {
        $status = $order->fulfillment_status;

        // Digital-only orders jump straight to "delivered" at payment; they get the receipt only.
        if ($order->wasChanged('fulfillment_status')
            && in_array($status, [FulfillmentStatus::Shipped, FulfillmentStatus::Delivered], true)
            && ($order->metadata['requires_shipping'] ?? false)) {
            SendTransactionalEmail::dispatch($order->getKey(), SendTransactionalEmail::SHIPPING_UPDATE, $status->value);
        }
    }
}
