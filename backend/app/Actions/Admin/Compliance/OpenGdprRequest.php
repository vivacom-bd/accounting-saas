<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Logs a data-subject request (plan Task 18). Tracking only: fulfilment is manual until plan step 4.7. The address is
 * stored as a sha256 of its lowercased form plus a masked display form, and never reaches the audit chain either.
 */
final class OpenGdprRequest
{
    public const int DUE_DAYS = 30;

    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly AdminAuditWriter $audit,
    ) {}

    /** Returns the new request's id. */
    public function handle(AdminActor $actor, string $kind, string $tenantId, string $subjectEmail, ?string $note): string
    {
        $email = mb_strtolower(trim($subjectEmail));
        $db = AdminTransaction::connection();

        return AdminTransaction::run(function () use ($db, $actor, $kind, $tenantId, $email, $note): string {
            if (! $db->table('tenants')->where('id', $tenantId)->exists()) {
                throw DomainRuleViolation::single('not_found', 'Organisation not found.');
            }
            $now = $this->clock->now();
            $id = $this->ids->next();
            $hash = hash('sha256', $email);
            $masked = self::mask($email);
            $db->table('gdpr_requests')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind,
                'subject_email_hash' => $hash, 'subject_email_masked' => $masked,
                'status' => 'open', 'opened_by' => $actor->id,
                'due_at' => $now->modify('+'.self::DUE_DAYS.' days')->format('Y-m-d H:i:s.uP'),
                'notes' => $note, 'created_at' => $now->format('Y-m-d H:i:s.uP'), 'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record($actor, 'gdpr.opened', $tenantId, 'gdpr_request', $id, null,
                ['kind' => $kind, 'subject_email_hash' => $hash, 'subject_email_masked' => $masked, 'status' => 'open']);

            return $id;
        });
    }

    /** `jane.doe@example.com` becomes `j***@example.com`. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
