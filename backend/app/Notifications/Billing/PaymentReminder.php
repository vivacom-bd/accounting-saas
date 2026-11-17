<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A reminder that a subscription payment is overdue: `$day` is the offset (1, 4 or 7) after the first failure (plan Task 12). */
final class PaymentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $organisation, public readonly int $day)
    {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->organisation}: payment overdue")
            ->line("The payment for {$this->organisation} is still outstanding. Update your payment method to keep your account fully working.")
            ->action('Open billing', rtrim((string) config('app.frontend_url'), '/').'/settings/billing');
    }
}
