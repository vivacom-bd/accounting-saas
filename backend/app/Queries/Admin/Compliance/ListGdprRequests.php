<?php

declare(strict_types=1);

namespace App\Queries\Admin\Compliance;

use App\Contracts\Clock;
use App\Support\Admin\AdminTransaction;

/** The GDPR request queue, most urgent first (plan Task 18). Tracking only; the plain email is not stored. */
final class ListGdprRequests
{
    public const string FULFILMENT_NOTE = 'Fulfilment is manual: this queue only tracks the request and its due date. Automated export and erasure arrive with plan step 4.7.';

    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  array{status?: string|null, kind?: string|null, tenant_id?: string|null, overdue?: bool|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $filters, int $limit = 200): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $query = AdminTransaction::connection()->table('gdpr_requests')->orderBy('due_at')->orderBy('id')->limit($limit);
        foreach (['status', 'kind', 'tenant_id'] as $column) {
            if (($filters[$column] ?? null) !== null) {
                $query->where($column, $filters[$column]);
            }
        }
        if (($filters['overdue'] ?? false) === true) {
            $query->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', $now);
        }

        $out = [];
        foreach ($query->get() as $row) {
            $out[] = self::resource((array) $row, $this->clock->now()->format('Y-m-d H:i:s.uP'));
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $row = AdminTransaction::connection()->table('gdpr_requests')->where('id', $id)->first();

        return $row === null ? null : self::resource((array) $row, $this->clock->now()->format('Y-m-d H:i:s.uP'));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private static function resource(array $r, string $now): array
    {
        $open = in_array($r['status'], ['open', 'in_progress'], true);
        $due = new \DateTimeImmutable((string) $r['due_at']);

        return [
            'id' => $r['id'],
            'tenant_id' => $r['tenant_id'],
            'kind' => $r['kind'],
            'subject_email_masked' => $r['subject_email_masked'],
            'status' => $r['status'],
            'opened_by' => $r['opened_by'],
            'due_at' => $due->format('c'),
            'overdue' => $open && $due < new \DateTimeImmutable($now),
            'completed_at' => $r['completed_at'] === null ? null : (new \DateTimeImmutable((string) $r['completed_at']))->format('c'),
            'notes' => $r['notes'],
            'created_at' => (new \DateTimeImmutable((string) $r['created_at']))->format('c'),
            'fulfilment' => 'manual',
            'fulfilment_note' => self::FULFILMENT_NOTE,
        ];
    }
}
