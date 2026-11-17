<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells organisation owners that a subscription payment failed (plan Task 12). Sent on-demand to each active owner. */
final class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $organisation, public readonly ?string $invoiceNumber)
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
        $invoice = $this->invoiceNumber === null ? 'your latest invoice' : "invoice {$this->invoiceNumber}";

        return (new MailMessage)
            ->subject("{$this->organisation}: payment failed")
            ->line("We could not collect payment for {$invoice}. Please update your payment method to avoid interruption.")
            ->action('Open billing', rtrim((string) config('app.frontend_url'), '/').'/settings/billing');
    }
}
