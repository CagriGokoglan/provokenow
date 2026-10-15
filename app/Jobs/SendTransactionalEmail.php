<?php

namespace App\Jobs;

use App\Enums\FulfillmentStatus;
use App\Mail\OrderReceiptMailable;
use App\Mail\ShipmentNotificationMailable;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Sends one order email (through the configured mailer: Brevo in production). Each
 * (type, order, status) is sent at most once: the send is recorded in orders.metadata.emails_sent,
 * so retries and repeated status changes never email the customer twice.
 */
class SendTransactionalEmail implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public const ORDER_CONFIRMATION = 'order_confirmation';

    public const SHIPPING_UPDATE = 'shipping_update';

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

    public function handle(): void
    {
        if (config('mail.default') === 'brevo' && blank(config('mail.mailers.brevo.key'))) {
            Log::info('Brevo is not configured; order email skipped', ['key' => $this->sentKey()]);

            return;
        }

        $order = Order::find($this->orderId);
        if (! $order || ! $order->customer_email || isset($order->metadata['emails_sent'][$this->sentKey()])) {
            return;
        }

        Mail::to($order->customer_email, $order->shipping_address['name'] ?? $order->user?->name)->send($this->mailable($order));

        $metadata = $order->fresh()->metadata ?? [];
        $metadata['emails_sent'][$this->sentKey()] = now()->toIso8601String();
        $order->forceFill(['metadata' => $metadata])->saveQuietly();
    }

    private function mailable(Order $order): Mailable
    {
        return match ($this->type) {
            self::ORDER_CONFIRMATION => new OrderReceiptMailable($order),
            self::SHIPPING_UPDATE => new ShipmentNotificationMailable($order, FulfillmentStatus::from($this->status ?? $order->fulfillment_status->value)),
            default => throw new InvalidArgumentException("Unknown email type [{$this->type}]."),
        };
    }

    private function sentKey(): string
    {
        return implode(':', array_filter([$this->type, $this->orderId, $this->status]));
    }
}
