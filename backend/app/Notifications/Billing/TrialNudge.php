<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An onboarding nudge sent on day 3, 7 or 12 of a trial (plan Task 16). */
final class TrialNudge extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $organisation, public readonly int $day, public readonly string $focus)
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
            ->subject("{$this->organisation}: {$this->focus}")
            ->line("You are on day {$this->day} of your Ledgerly trial.")
            ->line($this->focus)
            ->action('Open Ledgerly', rtrim((string) config('app.frontend_url'), '/').'/');
    }
}
