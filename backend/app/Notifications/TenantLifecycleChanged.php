<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells organisation owners that their organisation's lifecycle changed (plan Task 5, G5/G6). Carries no internal reason. */
final class TenantLifecycleChanged extends Notification implements ShouldQueue
{
    use Queueable;

    private const array SUMMARY = [
        'trial' => 'is on a trial.',
        'active' => 'is active again. Everything works as usual.',
        'past_due' => 'has an overdue payment. Please update your billing details to avoid interruption.',
        'read_only' => 'is now read-only: you can view and export your data, but not change it.',
        'suspended' => 'is suspended: you can still view your data, but not change it, until the account is settled.',
        'cancelled' => 'was cancelled and can no longer be opened. Contact us within 90 days to reactivate it.',
        'deleted' => 'was deleted.',
    ];

    public function __construct(public readonly string $organisation, public readonly string $from, public readonly string $to)
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
            ->subject("{$this->organisation}: account status changed")
            ->line("{$this->organisation} ".(self::SUMMARY[$this->to] ?? 'changed status.'))
            ->action('Open Ledgerly', rtrim((string) config('app.frontend_url'), '/').'/');
    }
}
