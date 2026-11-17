<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The staff invitation email (plan Task 8); the link carries the only copy of the raw set-password token. */
final class AdminInvitation extends Notification
{
    use Queueable;

    public const int VALID_HOURS = 24;

    public function __construct(public readonly string $token, public readonly string $name, public readonly string $inviter) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/admin/set-password?token='.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to the Ledgerly admin')
            ->greeting("Hello {$this->name},")
            ->line("{$this->inviter} invited you to the Ledgerly platform admin.")
            ->action('Set your password', $this->url())
            ->line('The link expires in '.self::VALID_HOURS.' hours and works once. You will set up two-factor authentication at your first sign-in.');
    }
}
