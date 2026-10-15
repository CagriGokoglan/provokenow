<?php

namespace App\Mail;

use App\Enums\FulfillmentStatus;
use App\Mail\Concerns\OrderEmailData;
use App\Models\Order;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "On its way" (courier, tracking, estimate) or "delivered", for physical orders. */
class ShipmentNotificationMailable extends Mailable
{
    use OrderEmailData;

    public function __construct(public readonly Order $order, public readonly FulfillmentStatus $status) {}

    public function envelope(): Envelope
    {
        $number = $this->order->order_number;

        return new Envelope(
            from: $this->brandSender(),
            replyTo: $this->brandReplyTo(),
            subject: $this->status === FulfillmentStatus::Delivered
                ? "Your PROVOKE NOW order {$number} has been delivered"
                : "Your PROVOKE NOW order {$number} is on its way",
            tags: ['shipping_update', $this->status->value],
        );
    }

    public function content(): Content
    {
        $order = $this->order;

        return new Content(view: 'emails.shipment', with: [
            ...$this->orderData($order),
            'delivered' => $this->status === FulfillmentStatus::Delivered,
            'courier' => $order->courier_name,
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_url,
            'shipped_on' => ($order->shipped_at ?? now())->format('l, j F Y'),
            'estimate' => $order->estimated_delivery_date?->format('l, j F Y') ?? config('provoke.default_delivery_estimate'),
            'estimate_is_date' => $order->estimated_delivery_date !== null,
            'orders_url' => FrontendUrl::to('/account/purchases'),
        ]);
    }
}
