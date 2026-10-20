<?php

namespace App\Console\Commands;

use App\Services\Gifts\GiftService;
use Illuminate\Console\Command;

/** Sends scheduled gifts whose day has come, and expires links nobody claimed in time. */
class DeliverGifts extends Command
{
    protected $signature = 'gifts:deliver';

    protected $description = 'Email scheduled gifts that are due and expire unclaimed ones';

    public function handle(GiftService $gifts): int
    {
        $sent = $gifts->deliverDue();
        $expired = $gifts->expireOverdue();
        $this->info("{$sent} gift(s) sent, {$expired} expired.");

        return self::SUCCESS;
    }
}
