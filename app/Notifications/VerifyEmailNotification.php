<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Verify Your Desire": a signed, expiring link to the web app's verification page. */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email address')
            ->view('emails.verify-email', [
                'name' => $notifiable->name,
                'url' => FrontendUrl::verifyEmail($notifiable),
                'expires_hours' => intdiv(config('provoke.auth.verification_expire'), 60),
                'support_email' => config('services.brevo.reply_to'),
            ]);
    }
}
