<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Tax\TaxPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxPeriod */
final class TaxPeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Computed values the controller puts on the model for the detail view; not columns.
        $extra = array_intersect_key($this->resource->getAttributes(), array_flip(['return', 'filed_return', 'changes_since_filing']));

        return array_replace([
            'id' => $this->id,
            'authority' => $this->authority,
            'starts_on' => Json::date($this->starts_on),
            'ends_on' => Json::date($this->ends_on),
            'due_on' => Json::date($this->due_on),
            'status' => $this->status,
            'filed_at' => Json::time($this->filed_at),
            'filed_by' => $this->filed_by,
            'authority_reference' => $this->authority_reference,
            'reopened_at' => Json::time($this->reopened_at),
            'reopened_by' => $this->reopened_by,
            'reopen_reason' => $this->reopen_reason,
            'manual_amounts' => (object) $this->manual_amounts,
            'version' => $this->lock_version,
            'changed_since_filing' => $this->when(array_key_exists('changed_since_filing', $this->resource->getAttributes()), fn () => (bool) $this->resource->getAttributes()['changed_since_filing']),
            'can' => Json::can($request, $this->resource, ['update' => 'update', 'file' => 'file', 'reopen' => 'reopen']),
        ], $extra);
    }
}
