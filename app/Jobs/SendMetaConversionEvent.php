<?php

namespace App\Jobs;

use App\Services\Marketing\MetaCapiService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/** Posts one pre-built (already hashed) event to the Meta Conversions API. */
class SendMetaConversionEvent implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly array $event) {}

    public function handle(MetaCapiService $meta): void
    {
        if ($meta->isEnabled()) {
            $meta->send($this->event);
        }
    }
}
