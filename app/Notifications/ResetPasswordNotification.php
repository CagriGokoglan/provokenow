<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset Your Passcode": a one-time, expiring link to the web app's reset page. */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $token)
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
            ->subject('Reset your password')
            ->view('emails.reset-password', [
                'name' => $notifiable->name,
                'url' => FrontendUrl::resetPassword($this->token, $notifiable->getEmailForPasswordReset()),
                'expires_minutes' => config('auth.passwords.users.expire'),
                'support_email' => config('services.brevo.reply_to'),
            ]);
    }
}
