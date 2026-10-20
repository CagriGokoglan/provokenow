<?php

namespace App\Mail;

use App\Mail\Concerns\OrderEmailData;
use App\Models\Gift;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the gift's recipient. Discreet by design: the subject and preview never name the product,
 * and no price appears anywhere. Three versions: waiting in your account (registered), claim
 * and set up your Vault (not registered), and a parcel on its way (physical-only gifts).
 */
class GiftReceivedMailable extends Mailable implements ShouldQueue
{
    use OrderEmailData, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly Gift $gift, public readonly ?string $claimToken)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->brandSender(),
            replyTo: $this->brandReplyTo(),
            subject: "{$this->gift->fromName()} sent you a gift",
            tags: ['gift_received'],
        );
    }

    public function content(): Content
    {
        $claimUrl = $this->claimToken ? FrontendUrl::to('/claim-gift?'.http_build_query(['token' => $this->claimToken])) : null;

        return new Content(view: 'emails.gift-received', with: [
            'from' => $this->gift->fromName(),
            // Not `message`: that name is the mail itself inside email views.
            'note' => $this->gift->gift_message,
            'registered' => $this->gift->recipient_id !== null,
            'claim_url' => $claimUrl,
            'ships' => (bool) ($this->gift->order->metadata['requires_shipping'] ?? false),
            'expires' => $this->gift->expires_at?->format('F j, Y'),
            'how_url' => FrontendUrl::to('/pages/how-gifting-works'),
        ]);
    }
}
