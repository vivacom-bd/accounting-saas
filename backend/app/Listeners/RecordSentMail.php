<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Mail\MailRecipient;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Admin support's mail log (plan Task 15, 10 §3.8): one row per recipient of a sent email, written through
 * sysops.record_mail_sent (hash and masked address, subject, notification or mailable class; the tenant comes from
 * the session). Never the body. Logging must never stop mail going out, so any failure is reported and swallowed; the
 * write runs in its own (savepoint) transaction so a database error cannot poison the caller's transaction.
 * Discovered automatically (handle() is typed on MessageSent).
 */
final class RecordSentMail
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->sent->getOriginalMessage();
            if (! $message instanceof Email) {
                return;
            }
            $notification = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;
            $subject = (string) $message->getSubject();
            $addresses = [];
            foreach ([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] as $address) {
                $addresses[] = $address->getAddress();
            }

            $db = DB::connection();
            $db->transaction(function () use ($db, $addresses, $subject, $notification): void {
                foreach (array_unique($addresses) as $address) {
                    $db->select('SELECT sysops.record_mail_sent(?, ?, ?, ?)', [
                        MailRecipient::hash($address), MailRecipient::mask($address), $subject, is_string($notification) ? $notification : null,
                    ]);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
