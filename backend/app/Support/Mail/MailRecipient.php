<?php

declare(strict_types=1);

namespace App\Support\Mail;

/** How the mail log refers to a recipient: a hash to match on, a masked form to show. The address itself is never stored. */
final class MailRecipient
{
    /** sha256 of the lower-cased, trimmed address. */
    public static function hash(string $address): string
    {
        return hash('sha256', mb_strtolower(trim($address)));
    }

    /** `jane@acme.co` becomes `j***@acme.co`; anything without an `@` becomes `***`. */
    public static function mask(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($address, 0, 1).'***'.substr($address, $at);
    }
}
