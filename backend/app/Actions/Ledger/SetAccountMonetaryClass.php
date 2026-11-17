<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Domain\Fx\MonetaryAccounts;
use App\Domain\Ledger\ControlType;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Ledger\Account;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Says whether an asset or liability account holds a monetary item (IAS 21.16), so the month-end revaluation takes it or
 * leaves it (plan 3c.29c): the tag `fx:monetary` or `fx:non_monetary` on `reporting_tags`; `null` removes it and the
 * account follows the subtype default again (see MonetaryAccounts). The typical use is a long-term or intragroup loan in a
 * non-current subtype, which is monetary. Nothing already posted changes; the next revaluation run reads the tag.
 *
 * Refused where it cannot apply: equity, income and expense; PPE, intangibles, inventory and their control accounts, which
 * are never monetary; and the receivable, payable, bank, cash and tax control accounts, which always are. Other tags are
 * kept. Asking for the state the account is already in changes nothing and writes no audit event.
 */
final class SetAccountMonetaryClass
{
    public const array CLASSES = ['monetary', 'non_monetary'];

    public function __construct(private readonly UnitOfWork $uow, private readonly SetupGuard $guard) {}

    public function handle(Actor $actor, string $accountId, int $version, ?string $class): Account
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $accountId, $version, $class) {
            $account = $locks->document(Account::query()->findOrFail($accountId), $version);
            $this->guard->require($actor, 'accounts.manage', $account->company_id);

            if ($class !== null && (
                ! in_array($account->account_type, ['asset', 'liability'], true)
                || ! MonetaryAccounts::mayBeMonetary($account->control_type, $account->account_subtype)
                || $account->control_type !== ControlType::None
            )) {
                throw DomainRuleViolation::single('monetary_class_not_applicable', 'Only an asset or liability that is not PPE, an intangible, inventory or a control account can be classed monetary or non-monetary.');
            }

            $tags = $account->reporting_tags;
            $kept = array_values(array_filter($tags, fn (string $t): bool => ! in_array($t, [MonetaryAccounts::TAG_MONETARY, MonetaryAccounts::TAG_NON_MONETARY], true)));
            $next = $class === null ? $kept : [...$kept, 'fx:'.$class];
            if ($next === $tags) {
                return $account;
            }

            $before = $account->attributesToArray();
            $account->forceFill(['reporting_tags' => $next, 'lock_version' => $account->lock_version + 1])->save();
            $account->refresh();
            $audit->add('account.monetary_class_set', $account, before: $before, after: $account, context: ['class' => $class]);

            return $account;
        });
    }
}
