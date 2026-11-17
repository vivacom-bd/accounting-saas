<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organisation\SupportAccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Optional;

/**
 * Whether platform support may currently impersonate members of the organisation, and until when (plan Task 14, G4).
 * Wraps the assembled array (see of()); the shape below is the contract.
 *
 * @mixin Optional
 */
final class SupportAccessResource extends JsonResource
{
    public static function of(?SupportAccessGrant $grant): self
    {
        return new self([
            'open' => $grant !== null,
            'grant' => $grant === null ? null : [
                'id' => $grant->id,
                'hours' => $grant->hours,
                'expires_at' => $grant->expires_at->format(DATE_RFC3339),
                'granted_by' => $grant->granted_by,
                'created_at' => $grant->created_at->format(DATE_RFC3339),
            ],
        ]);
    }

    /**
     * @return array{
     *     open: bool,
     *     grant: array{id: string, hours: int, expires_at: string, granted_by: string, created_at: string}|null
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{open: bool, grant: array{id: string, hours: int, expires_at: string, granted_by: string, created_at: string}|null} $access */
        $access = $this->resource;

        return $access;
    }
}
