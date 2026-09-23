<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The confirmation email: a six-digit code to type on the site, and the
 * signed link for anyone who would rather click. Either one confirms.
 */
class VerifyEmailWithCode extends VerifyEmail
{
    public function __construct(public readonly string $code) {}

    public function toMail($notifiable): MailMessage
    {
        $spaced = substr($this->code, 0, 3).' '.substr($this->code, 3);

        return (new MailMessage)
            ->subject("Your codeinchrome code: $spaced")
            ->line("Your confirmation code is **$spaced**.")
            ->line('Type it on the page you signed up from. It expires in 15 minutes and works once.')
            ->action('Or confirm with one click', $this->verificationUrl($notifiable))
            ->line('If you did not create a codeinchrome account, ignore this email; nothing happens without the code.');
    }
}
