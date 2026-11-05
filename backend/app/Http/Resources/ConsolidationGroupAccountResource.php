<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consolidation\ConsolidationGroupAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A group account. A `role` marks an account the engine posts to (retained earnings, translation reserve, ...).
 *
 * @mixin ConsolidationGroupAccount
 */
final class ConsolidationGroupAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type,
            'account_subtype' => $this->account_subtype,
            'role' => $this->role,
            'is_active' => $this->is_active,
        ];
    }
}
