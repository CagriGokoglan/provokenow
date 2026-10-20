<?php

namespace App\Mail;

use App\Mail\Concerns\OrderEmailData;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Lost access to device?": a one-time sign-in code (sent immediately, not queued). */
class TwoFactorEmailCodeMailable extends Mailable
{
    use OrderEmailData;

    public function __construct(public readonly string $code, public readonly int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->brandSender(),
            replyTo: $this->brandReplyTo(),
            subject: 'Admin Panel Login Verification Code',
            tags: ['two_factor_email_code'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.two-factor-code', with: ['code' => $this->code, 'minutes' => $this->minutes]);
    }
}
