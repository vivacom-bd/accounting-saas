<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Queries\Consolidation\UnmappedBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnmappedBalance */
final class UnmappedBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'member_id' => $this->memberId,
            'company_id' => $this->companyId,
            'account_id' => $this->accountId,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->accountType,
            'net' => $this->net,
            'movement_debit' => $this->movementDebit,
            'movement_credit' => $this->movementCredit,
        ];
    }
}
