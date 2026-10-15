<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTransactionalEmail;
use App\Services\Marketing\MetaCapiService;

class QueueOrderPaidNotifications
{
    public function __construct(private readonly MetaCapiService $meta) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        SendTransactionalEmail::dispatch($order->getKey(), SendTransactionalEmail::ORDER_CONFIRMATION);

        if ($purchase = $this->meta->purchaseEvent($order)) {
            SendMetaConversionEvent::dispatch($purchase);
        }
    }
}
