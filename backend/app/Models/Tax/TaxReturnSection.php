<?php

declare(strict_types=1);

namespace App\Models\Tax;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One labelled line of a company's tax return, from a country pack (plan 3c.22).
 *
 * @property string $id
 * @property string $company_id
 * @property string $section_key
 * @property string $pack_key
 * @property string $group_key output | input | adjustments | credits
 * @property string $label
 * @property string|null $note
 * @property int $sort
 * @property string $effect add | subtract | none
 * @property string $source_kind codes | reverse_charge_output | reverse_charge_input | manual
 */
class TaxReturnSection extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    /** @return HasMany<TaxReturnSectionCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(TaxReturnSectionCode::class, 'section_id');
    }
}
