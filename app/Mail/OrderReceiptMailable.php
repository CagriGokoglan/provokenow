<?php

namespace App\Mail;

use App\Mail\Concerns\OrderEmailData;
use App\Models\Order;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Purchase confirmation. Adapts to the order: deck access links and a library button for
 * digital decks; item breakdown, delivery address and the discreet-packaging note for parcels.
 * Sent from the queued SendTransactionalEmail job (once per order).
 */
class OrderReceiptMailable extends Mailable
{
    use OrderEmailData;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->brandSender(),
            replyTo: $this->brandReplyTo(),
            subject: "Your PROVOKE NOW order {$this->order->order_number} is confirmed",
            tags: ['order_confirmation'],
        );
    }

    public function content(): Content
    {
        $this->order->loadMissing('entitlements.deck');

        return new Content(view: 'emails.order-receipt', with: [
            ...$this->orderData($this->order),
            'decks' => $this->order->entitlements
                ->filter(fn ($e) => $e->deck !== null)
                ->map(fn ($e) => ['title' => $e->deck->title, 'code' => $e->deck->deck_code, 'url' => FrontendUrl::libraryDeck($e->deck)])
                ->values()
                ->all(),
            'library_url' => FrontendUrl::to('/account/library'),
        ]);
    }
}
