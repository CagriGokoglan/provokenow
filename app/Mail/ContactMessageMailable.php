<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** A contact form message, delivered to the support inbox. Replying goes straight to the visitor. */
class ContactMessageMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly ContactMessage $contact)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('services.brevo.sender.email'), config('services.brevo.sender.name')),
            replyTo: [new Address($this->contact->email, $this->contact->name)],
            subject: '[Contact] '.Str::limit(str_replace(["\r", "\n"], ' ', $this->contact->subject), 120),
            tags: ['contact_form'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-message', with: ['contact' => $this->contact]);
    }
}
