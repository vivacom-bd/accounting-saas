<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consolidation\ConsolidationAccountMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mapping from a member account to a group account, with its intercompany flags.
 *
 * @mixin ConsolidationAccountMap
 */
final class ConsolidationMapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'account_id' => $this->account_id,
            'group_account_id' => $this->group_account_id,
            /** Intercompany class of the account (balance, loan, income_expense, dividend), null when it is not intercompany (D16.7). */
            'ic_class' => $this->ic_class,
            /** The member the whole account is with; null = taken from each line's contact. */
            'counterparty_member_id' => $this->counterparty_member_id,
            /** A loan designated part of the net investment in a foreign operation (IAS 21.32). */
            'net_investment' => $this->net_investment,
        ];
    }
}
