<?php

namespace App\Mail;

use App\Models\Deck;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * New decks unlocked without a purchase (e.g. granted from the admin). Purchases are covered by
 * the order receipt, which already lists the decks. Queued, after the granting transaction commits.
 */
class DigitalAccessGrantedMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @param list<string> $deckIds */
    public function __construct(public readonly User $user, public readonly array $deckIds)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $count = count($this->deckIds);

        return new Envelope(
            from: new Address(config('services.brevo.sender.email'), config('services.brevo.sender.name')),
            replyTo: [new Address(config('services.brevo.reply_to'))],
            subject: $count === 1 ? 'A new deck is waiting in your library' : "{$count} new decks are waiting in your library",
            tags: ['access_granted'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.access-granted', with: [
            'name' => $this->user->name,
            'decks' => Deck::whereIn('id', $this->deckIds)->orderBy('deck_code')->get()
                ->map(fn (Deck $d) => ['title' => $d->title, 'code' => $d->deck_code, 'url' => FrontendUrl::libraryDeck($d)])
                ->all(),
            'library_url' => FrontendUrl::to('/account/library'),
            'support_email' => config('services.brevo.reply_to'),
        ]);
    }
}
