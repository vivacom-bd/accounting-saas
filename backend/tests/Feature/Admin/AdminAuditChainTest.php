<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\TamperAudit;

function adminActor(): AdminActor
{
    return new AdminActor((string) Str::uuid7(), AdminRole::Owner, '203.0.113.9', 'pest', 'req-1');
}

/** @return list<int> */
function recordAdminEvents(int $n): array
{
    $actor = adminActor();
    $ids = [];
    AdminTransaction::run(function () use ($n, $actor, &$ids) {
        $writer = app(AdminAuditWriter::class);
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = $writer->record($actor, 'tenant.note', null, 'tenant', "t{$i}", null, ['n' => $i], 'because');
        }
    });

    return $ids;
}

it('records events in a hash chain', function () {
    $ids = recordAdminEvents(3);

    $rows = DB::connection('pgsql_platform')->table('admin_audit_events')->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->prev_hash)->toBeNull()
        ->and($rows[1]->prev_hash)->toBe($rows[0]->hash)
        ->and($rows[2]->prev_hash)->toBe($rows[1]->hash)
        ->and($rows[0]->hash)->toBe(hash('sha256', $rows[0]->canonical))
        ->and($rows[1]->hash)->toBe(hash('sha256', $rows[0]->hash.$rows[1]->canonical));

    $head = DB::connection('pgsql_platform')->table('admin_audit_head')->first();
    expect($head->last_id)->toBe($ids[2])->and($head->last_hash)->toBe($rows[2]->hash);
});

it('passes the verify command on an intact chain', function () {
    recordAdminEvents(3);

    $this->artisan('admin-audit:verify')->expectsOutputToContain('Admin audit chain intact (3 events)')->assertExitCode(0);
});

it('passes the verify command on an empty chain', function () {
    $this->artisan('admin-audit:verify')->expectsOutputToContain('Admin audit chain intact (0 events)')->assertExitCode(0);
});

it('names the first tampered event', function () {
    $ids = recordAdminEvents(3);

    TamperAudit::admin(fn ($owner) => $owner->table('admin_audit_events')->where('id', $ids[1])->update(['action' => 'tenant.deleted']));

    $this->artisan('admin-audit:verify')->expectsOutputToContain("event {$ids[1]}")->assertExitCode(1);
});

it('detects a removed event through the head', function () {
    $ids = recordAdminEvents(3);

    TamperAudit::admin(fn ($owner) => $owner->table('admin_audit_events')->where('id', $ids[2])->delete());

    $this->artisan('admin-audit:verify')->assertExitCode(1);
});

it('refuses to record outside a transaction', function () {
    expect(fn () => app(AdminAuditWriter::class)->record(adminActor(), 'x', null, 'tenant', null))->toThrow(LogicException::class);
});
