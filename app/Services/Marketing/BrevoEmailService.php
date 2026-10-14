<?php

namespace App\Services\Marketing;

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Support\FrontendUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Brevo (ex-Sendinblue) transactional email over API v3.
 *
 * Uses a Brevo template when one is configured (services.brevo.templates.*), otherwise
 * sends the built-in Blade template as htmlContent. Subjects are deliberately discreet.
 */
class BrevoEmailService
{
    public const ORDER_CONFIRMATION = 'order_confirmation';

    public const SHIPPING_UPDATE = 'shipping_update';

    public function isEnabled(): bool
    {
        return filled(config('services.brevo.key'));
    }

    public function sendOrderConfirmation(Order $order): Response
    {
        $order->loadMissing('items', 'entitlements.deck');

        $params = [
            ...$this->orderParams($order),
            'deck_access' => $order->entitlements
                ->filter(fn ($e) => $e->deck !== null)
                ->map(fn ($e) => ['title' => $e->deck->title, 'url' => FrontendUrl::libraryDeck($e->deck)])
                ->values()
                ->all(),
            'library_url' => FrontendUrl::to('/library'),
        ];

        return $this->send(
            type: self::ORDER_CONFIRMATION,
            order: $order,
            subject: "Your PROVOKE NOW order {$order->order_number} is confirmed",
            view: 'emails.order-confirmation',
            params: $params,
        );
    }

    public function sendShippingUpdate(Order $order): Response
    {
        $status = $order->fulfillment_status;
        $subject = $status === FulfillmentStatus::Delivered
            ? "Your PROVOKE NOW order {$order->order_number} has been delivered"
            : "Your PROVOKE NOW order {$order->order_number} is on its way";

        return $this->send(
            type: self::SHIPPING_UPDATE,
            order: $order,
            subject: $subject,
            view: 'emails.shipping-update',
            params: [
                ...$this->orderParams($order),
                'status' => $status->value,
                'tracking_number' => $order->metadata['tracking_number'] ?? null,
                'tracking_url' => $order->metadata['tracking_url'] ?? null,
            ],
        );
    }

    private function send(string $type, Order $order, string $subject, string $view, array $params): Response
    {
        $sender = config('services.brevo.sender');
        $templateId = config("services.brevo.templates.{$type}");
        $recipientName = $order->shipping_address['name'] ?? $order->user?->name;

        $payload = array_filter([
            'sender' => ['email' => $sender['email'], 'name' => $sender['name']],
            'to' => [array_filter(['email' => $order->customer_email, 'name' => $recipientName])],
            'replyTo' => ['email' => config('services.brevo.reply_to')],
            'subject' => $subject,
            'templateId' => $templateId ? (int) $templateId : null,
            'params' => $templateId ? $params : null,
            'htmlContent' => $templateId ? null : view($view, $params)->render(),
            'tags' => [$type],
            // Brevo drops duplicates with the same key, so a retried job cannot double-send.
            'headers' => ['idempotencyKey' => "{$type}-{$order->getKey()}-".($params['status'] ?? 'v1')],
        ]);

        return Http::acceptJson()
            ->withHeaders(['api-key' => config('services.brevo.key')])
            ->timeout(10)
            ->post(config('services.brevo.endpoint'), $payload)
            ->throw();
    }

    private function orderParams(Order $order): array
    {
        $money = fn (int $cents) => number_format($cents / 100, 2).' '.$order->currency;

        return [
            'order_number' => $order->order_number,
            'customer_name' => $order->shipping_address['name'] ?? $order->user?->name,
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->metadata['name'] ?? 'Item',
                'quantity' => $i->quantity,
                'total' => $money($i->subtotal_cents),
            ])->values()->all(),
            'subtotal' => $money($order->subtotal_cents),
            'discount' => $order->discount_cents > 0 ? $money($order->discount_cents) : null,
            'shipping' => $money($order->shipping_cents),
            'tax' => $money($order->tax_cents),
            'total' => $money($order->total_cents),
            'support_email' => config('services.brevo.reply_to'),
        ];
    }
}
