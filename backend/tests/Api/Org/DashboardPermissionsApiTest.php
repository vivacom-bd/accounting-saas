<?php

declare(strict_types=1);

/**
 * Every call the live dashboard makes is authorized on its own permission (plan 3c.26c): a role holding only the matching
 * right gets that panel's data and a 403 on every other panel's source.
 */
beforeEach(function (): void {
    $this->setUpTrade();
    $this->boss = $this->person('Bea Boss', ['Owner'], org: 'admin');
    $today = date('Y-m-d');
    $year = date('Y');
    /** @var array<string, array{0: string, 1: string}> permission → [path, query] of the panel source it alone unlocks */
    $this->sources = [
        'reports.view' => ['reports/ar-aging', "as_of={$today}"],
        'banking.view' => ['bank-accounts', ''],
        'journals.approve' => ['approvals', ''],
        'invoices.view' => ['invoices', 'overdue=true&limit=1'],
        'bills.view' => ['bills', 'overdue=true&limit=1'],
        'accounts.view' => ['accounts', 'limit=1'],
        'periods.view' => ['fiscal-years', ''],
        'audit.view' => ['audit-events', 'limit=1'],
    ];
    // Reports share one permission; the other report panels (AP aging, P&L, cash flow, trend) follow it.
    $this->reportSources = [
        ['reports/ap-aging', "as_of={$today}"],
        ['reports/profit-and-loss', "from={$year}-01-01&to={$today}"],
        ['reports/cash-flow', "from={$year}-01-01&to={$today}"],
    ];
    $this->roleWith = function (string ...$permissions) {
        $permission = implode('+', $permissions);
        $this->asPerson($this->boss);
        $role = $this->postJson($this->api('roles'), ['name' => 'Only '.$permission, 'permissions' => $permissions], $this->key())->assertCreated()->json('data');
        $person = $this->person('Pat '.$permission, []);
        $this->putJson($this->api("members/{$person->id}/roles/{$role['id']}"), [], $this->key())->assertOk();

        return $person;
    };
});

it('answers each dashboard source only to the role holding that source\'s permission', function (): void {
    foreach ($this->sources as $permission => [$path]) {
        $person = ($this->roleWith)($permission);
        $this->asPerson($person);
        foreach ($this->sources as $other => [$otherPath, $otherQuery]) {
            $response = $this->getJson($this->api($otherPath.($otherQuery === '' ? '' : '?'.$otherQuery)));
            if ($other === $permission) {
                expect($response->status())->toBeIn([200], "{$permission} should open {$otherPath}");
            } else {
                expect($response->status())->toBe(403, "{$permission} must not open {$otherPath}");
            }
        }
        foreach ($this->reportSources as [$reportPath, $reportQuery]) {
            $status = $this->getJson($this->api($reportPath.'?'.$reportQuery))->status();
            expect($status === 200)->toBe($permission === 'reports.view', "{$permission} on {$reportPath}: {$status}");
        }
    }
});

it('opens the budget note only to a role holding both the budget and the report right', function (): void {
    foreach ([['budgets.view', false], ['reports.view', false], ['budgets.view+reports.view', true]] as [$held, $ok]) {
        $this->asPerson(($this->roleWith)(...explode('+', $held)));
        $this->getJson($this->api('budgets/dashboard-card'))->assertStatus($ok ? 200 : 403);
    }
});
