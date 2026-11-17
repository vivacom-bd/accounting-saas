<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Warns owners that the trial ends in `$daysLeft` days (3 or 1; plan Task 12). */
final class TrialEnding extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $organisation, public readonly int $daysLeft)
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
        $when = $this->daysLeft === 1 ? 'tomorrow' : "in {$this->daysLeft} days";

        return (new MailMessage)
            ->subject("{$this->organisation}: your trial ends {$when}")
            ->line("The trial of {$this->organisation} ends {$when}. Choose a plan to keep working without interruption.")
            ->action('Choose a plan', rtrim((string) config('app.frontend_url'), '/').'/settings/billing');
    }
}
