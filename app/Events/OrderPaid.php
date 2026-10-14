<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired once, when an order transitions to paid. */
class OrderPaid
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
