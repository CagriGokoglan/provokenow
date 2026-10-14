<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Marketing\BrevoEmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Sends one order email through Brevo. Each (type, order, status) is sent at most once:
 * the send is recorded in orders.metadata.emails_sent.
 */
class SendTransactionalEmail implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $orderId,
        public readonly string $type,
        public readonly ?string $status = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->sentKey();
    }

    public function handle(BrevoEmailService $brevo): void
    {
        if (! $brevo->isEnabled()) {
            Log::info('Brevo disabled; transactional email skipped', ['key' => $this->sentKey()]);

            return;
        }

        $order = Order::find($this->orderId);
        if (! $order || isset($order->metadata['emails_sent'][$this->sentKey()])) {
            return;
        }

        match ($this->type) {
            BrevoEmailService::ORDER_CONFIRMATION => $brevo->sendOrderConfirmation($order),
            BrevoEmailService::SHIPPING_UPDATE => $brevo->sendShippingUpdate($order),
            default => throw new InvalidArgumentException("Unknown email type [{$this->type}]."),
        };

        $metadata = $order->fresh()->metadata ?? [];
        $metadata['emails_sent'][$this->sentKey()] = now()->toIso8601String();
        $order->forceFill(['metadata' => $metadata])->saveQuietly();
    }

    private function sentKey(): string
    {
        return implode(':', array_filter([$this->type, $this->orderId, $this->status]));
    }
}
