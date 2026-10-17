<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset email for a brand owner (auth.passwords.brand_owners).
 * Links to the owner reset page, not the tenant panel's — the framework's
 * default ResetPassword notification would build a `password.reset` URL.
 */
class BrandOwnerResetPassword extends Notification
{
    public function __construct(public string $token, public string $email) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.brand_owners.expire', 60);
        $url = route('owner.password.reset', ['token' => $this->token]).'?email='.urlencode($this->email);

        return (new MailMessage)
            ->subject('Reset your MetaSoft BD brand owner password')
            ->line('We received a request to reset the password for your MetaSoft BD brand owner account.')
            ->action('Reset password', $url)
            ->line("This link works once and expires in {$minutes} minutes.")
            ->line('If you did not ask for this, you can ignore this email — your password will not change.');
    }
}
