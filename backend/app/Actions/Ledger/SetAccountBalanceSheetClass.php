<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Domain\Reports\BalanceSheetClassifier;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Ledger\Account;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Sets how an asset or liability account is presented on the balance sheet (IAS 1.60): the company's own answer is the tag
 * `bs:current` or `bs:non_current` on `reporting_tags`; `null` removes it and the account follows the rules again (plan 3c.21b).
 * Presentation only: no balance, posting or report total changes, only the current / non-current subtotal the account falls in.
 * Other tags (cash flow `cf:*`) are kept. Deferred tax is always non-current and refuses a tag. Asking for the state the
 * account is already in changes nothing and writes no audit event.
 */
final class SetAccountBalanceSheetClass
{
    public const array CLASSES = ['current', 'non_current'];

    public function __construct(private readonly UnitOfWork $uow, private readonly SetupGuard $guard) {}

    public function handle(Actor $actor, string $accountId, int $version, ?string $class): Account
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $accountId, $version, $class) {
            $account = $locks->document(Account::query()->findOrFail($accountId), $version);
            $this->guard->require($actor, 'accounts.manage', $account->company_id);

            if (! in_array($account->account_type, ['asset', 'liability'], true)) {
                throw DomainRuleViolation::single('balance_sheet_class_not_applicable', 'Only asset and liability accounts are split into current and non-current.');
            }
            if ($class !== null && BalanceSheetClassifier::isDeferredTax($account->ifrs_element)) {
                throw DomainRuleViolation::single('balance_sheet_class_fixed', 'Deferred tax is always non-current (IAS 1.56).');
            }

            $tags = $account->reporting_tags;
            $kept = array_values(array_filter($tags, fn (string $t): bool => ! str_starts_with($t, 'bs:')));
            $next = $class === null ? $kept : [...$kept, 'bs:'.$class];
            if ($next === $tags) {
                return $account;
            }

            $before = $account->attributesToArray();
            $account->forceFill(['reporting_tags' => $next, 'lock_version' => $account->lock_version + 1])->save();
            $account->refresh();
            $audit->add('account.balance_sheet_class_set', $account, before: $before, after: $account, context: ['class' => $class]);

            return $account;
        });
    }
}
