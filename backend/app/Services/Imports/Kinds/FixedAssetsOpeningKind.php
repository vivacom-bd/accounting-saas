<?php

declare(strict_types=1);

namespace App\Services\Imports\Kinds;

use App\Actions\FixedAssets\CreateFixedAsset;
use App\Actions\FixedAssets\Inputs\FixedAssetInput;
use App\Actions\Ledger\SetupGuard;
use App\Domain\Banking\AmountParser;
use App\Domain\Banking\DateParser;
use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Violation;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetTaxClass;
use App\Models\Organisation\Company;
use App\Services\Imports\FieldSpec;
use App\Services\Imports\ImportContext;
use App\Services\Imports\ImportKind;
use App\Services\Imports\ImportRunner;
use App\Services\Imports\PreparedSet;
use App\Services\Imports\RowOutcome;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use InvalidArgumentException;

/**
 * Assets already owned at go-live, one per row, entered in the register through `CreateFixedAsset`'s opening path: cost and
 * accumulated depreciation as stated at `options.as_of`, and the months already depreciated worked out from the fiscal periods. It
 * posts nothing; the cost and the accumulated depreciation reach the ledger through the opening balances, and the register-to-ledger
 * check shows whether the two agree. All rows or none: one bad row refuses the apply, and a failure while applying leaves no asset
 * behind. Register numbers come from the gapless sequence, so a file cannot choose them; a category is named as in the category list.
 */
final class FixedAssetsOpeningKind extends ImportKind
{
    /** @var array<string, AssetCategory|null> */
    private array $categories = [];

    /** @var array<string, string|null> */
    private array $taxClasses = [];

    public function __construct(private readonly CreateFixedAsset $assets, private readonly SetupGuard $guard) {}

    public function key(): string
    {
        return 'fixed_assets_opening';
    }

    public function permission(): string
    {
        return 'fixed_assets.manage';
    }

    public function atomic(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            new FieldSpec('name', 'Name', true, ['asset', 'asset name', 'description of asset']),
            new FieldSpec('category', 'Category', true, ['asset category', 'class', 'asset class'], 'the name of a category of this company'),
            new FieldSpec('acquired_on', 'Acquisition date', true, ['acquired', 'date acquired', 'purchase date', 'date of purchase', 'acquisition']),
            new FieldSpec('available_from', 'Available-for-use date', false, ['available for use', 'in service date', 'date in service', 'available from'], 'the acquisition date when not given'),
            new FieldSpec('cost', 'Cost', true, ['original cost', 'purchase cost', 'acquisition cost', 'gross cost']),
            new FieldSpec('accumulated', 'Accumulated depreciation', false, ['accumulated', 'accum depreciation', 'accumulated depreciation to date', 'depreciation to date'], 'as stated at the opening date; none when not given'),
            new FieldSpec('residual', 'Residual value', false, ['salvage value', 'salvage', 'scrap value'], 'none when not given'),
            new FieldSpec('method', 'Method', false, ['depreciation method'], 'straight line, declining balance or units of production; the category\'s when not given'),
            new FieldSpec('life_months', 'Useful life (months)', false, ['useful life', 'life months', 'life'], 'the category\'s when not given'),
            new FieldSpec('declining_rate', 'Declining rate (%)', false, ['rate', 'depreciation rate', 'annual rate'], 'a yearly percentage, for declining balance'),
            new FieldSpec('units_total', 'Total units', false, ['total expected units', 'expected units'], 'for units of production'),
            new FieldSpec('tax_class', 'Tax class code', false, ['tax class'], 'a tax depreciation class code of this company'),
            new FieldSpec('description', 'Description', false, ['notes', 'memo']),
        ];
    }

    public function optionRules(Company $company): array
    {
        return ['as_of' => ['required', 'date_format:Y-m-d']];
    }

    /** @param array<string, mixed> $options @return list<string> */
    public function missingOptions(array $options): array
    {
        return isset($options['as_of']) ? [] : ['as_of'];
    }

    public function prepare(ImportContext $ctx, array $rows): PreparedSet
    {
        $set = new PreparedSet;
        $cost = 0;
        $accumulated = 0;
        foreach ($rows as $number => $cells) {
            $outcome = $this->row($ctx, $cells);
            $set->rows[$number] = $outcome;
            if ($outcome->prepared instanceof FixedAssetInput) {
                $cost += (int) $outcome->prepared->cost;
                $accumulated += $outcome->prepared->openingAccumulated;
            }
        }
        $set->summary = ['as_of' => (string) $ctx->option('as_of'), 'assets' => count($rows), 'total_cost' => $cost, 'total_accumulated_depreciation' => $accumulated, 'total_carrying_amount' => $cost - $accumulated];

        return $set;
    }

    public function apply(ImportContext $ctx, Actor $actor, ImportRunner $run): void
    {
        $rows = $run->pending();
        if ($rows->isEmpty()) {
            return;
        }
        $run->unit(ImportRunner::ids($rows), function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($ctx, $actor, $rows): array {
            $this->guard->require($actor, $this->permission(), $ctx->company->id);

            // Checked again against the world as it is now; one problem refuses every row, and the unit rolls back with it.
            $fresh = $this->prepare($ctx, $rows->mapWithKeys(fn ($r) => [$r->row_number => $r->cells])->all());
            $problems = [];
            foreach ($fresh->rows as $number => $outcome) {
                foreach ($outcome->errors as $e) {
                    $problems[] = new Violation($e['code'], "Row {$number}: {$e['message']}", $number);
                }
            }
            if ($problems !== []) {
                throw new DomainRuleViolation($problems[0]->code, 'The assets no longer pass validation.', $problems);
            }

            $numbers = [];
            foreach ($fresh->rows as $outcome) {
                assert($outcome->prepared instanceof FixedAssetInput);
                $numbers[] = $this->assets->handle($actor, $outcome->prepared)->number;
            }

            return ['assets' => count($numbers), 'first_number' => $numbers[0], 'last_number' => $numbers[count($numbers) - 1]];
        });
    }

    /** @param array<string, string> $cells */
    private function row(ImportContext $ctx, array $cells): RowOutcome
    {
        $o = new RowOutcome;
        $name = $ctx->value($cells, 'name');
        if ($name === null) {
            $o->add('name', 'required', 'The name is empty.');
        } elseif (mb_strlen($name) > 200) {
            $o->add('name', 'too_long', 'The name is longer than 200 characters.');
        }

        $category = null;
        $categoryName = $ctx->value($cells, 'category');
        if ($categoryName === null) {
            $o->add('category', 'required', 'The category is empty.');
        } elseif (($category = $this->category($ctx, $categoryName)) === null) {
            $o->add('category', 'name_not_found', "There is no asset category named {$categoryName} in this company.");
        }

        $acquired = $this->date($ctx, $cells, 'acquired_on', $o, true);
        $available = $this->date($ctx, $cells, 'available_from', $o, false);
        $cost = $this->money($ctx, $cells, 'cost', $o, true);
        $accumulated = $this->money($ctx, $cells, 'accumulated', $o, false) ?? 0;
        $residual = $this->money($ctx, $cells, 'residual', $o, false) ?? 0;
        $life = $this->whole($ctx, $cells, 'life_months', $o);
        $units = $this->whole($ctx, $cells, 'units_total', $o);

        $rate = null;
        $rawRate = $ctx->value($cells, 'declining_rate');
        if ($rawRate !== null) {
            try {
                $rate = AmountParser::parse(rtrim($rawRate, " %\u{00A0}"), $ctx->locale(), 2);
            } catch (InvalidArgumentException) {
                $o->add('declining_rate', 'invalid_rate', "'{$rawRate}' is not a percentage with at most two decimals.");
            }
        }

        $method = null;
        $rawMethod = $ctx->value($cells, 'method');
        if ($rawMethod !== null) {
            $method = str_replace([' ', '-'], '_', mb_strtolower($rawMethod));
            if (! in_array($method, DepreciationMethod::values(), true)) {
                $o->add('method', 'method_invalid', "'{$rawMethod}' is not a depreciation method: use straight line, declining balance or units of production.");
                $method = null;
            }
        }

        $taxClassId = null;
        $taxCode = $ctx->value($cells, 'tax_class');
        if ($taxCode !== null && ($taxClassId = $this->taxClassId($ctx, $taxCode)) === null) {
            $o->add('tax_class', 'code_not_found', "There is no tax depreciation class with code {$taxCode} in this company.");
        }

        if (! $o->ok() || $category === null || $name === null || $cost === null || $acquired === null) {
            return $o;
        }

        $input = new FixedAssetInput(
            companyId: $ctx->company->id, name: $name, categoryId: $category->id, acquiredOn: $acquired, availableFrom: $available, cost: $cost,
            residual: $residual, method: $method, lifeMonths: $life, decliningRateBp: $rate, unitsTotal: $units, description: $ctx->value($cells, 'description'),
            openingAccumulated: $accumulated, openingAsOf: (string) $ctx->option('as_of'), taxClassId: $taxClassId,
        );
        try {
            $this->assets->checkOpening($input);
        } catch (DomainRuleViolation $e) {
            $o->add(null, $e->errorCode, $e->getMessage());

            return $o;
        }
        $o->prepared = $input;

        return $o;
    }

    /** @param array<string, string> $cells */
    private function date(ImportContext $ctx, array $cells, string $field, RowOutcome $o, bool $required): ?string
    {
        $raw = $ctx->value($cells, $field);
        if ($raw === null) {
            if ($required) {
                $o->add($field, 'required', 'The date is empty.');
            }

            return null;
        }
        try {
            return DateParser::parse($raw, $ctx->dateFormat());
        } catch (InvalidArgumentException $e) {
            $o->add($field, 'invalid_date', $e->getMessage());

            return null;
        }
    }

    /** @param array<string, string> $cells */
    private function money(ImportContext $ctx, array $cells, string $field, RowOutcome $o, bool $required): ?int
    {
        $raw = $ctx->value($cells, $field);
        if ($raw === null) {
            if ($required) {
                $o->add($field, 'required', 'The amount is empty.');
            }

            return null;
        }
        try {
            $amount = AmountParser::parse($raw, $ctx->locale(), $ctx->scale());
        } catch (InvalidArgumentException $e) {
            $o->add($field, 'invalid_amount', $e->getMessage());

            return null;
        }
        if ($amount < 0) {
            $o->add($field, 'negative_amount', 'Amounts must not be negative.');

            return null;
        }

        return $amount;
    }

    /** @param array<string, string> $cells */
    private function whole(ImportContext $ctx, array $cells, string $field, RowOutcome $o): ?int
    {
        $raw = $ctx->value($cells, $field);
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^\d{1,9}$/', $raw) !== 1) {
            $o->add($field, 'invalid_number', "'{$raw}' is not a whole number.");

            return null;
        }

        return (int) $raw;
    }

    private function category(ImportContext $ctx, string $name): ?AssetCategory
    {
        $key = $ctx->company->id.'|'.mb_strtolower($name);

        return $this->categories[$key] ??= AssetCategory::query()->where('company_id', $ctx->company->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    private function taxClassId(ImportContext $ctx, string $code): ?string
    {
        $key = $ctx->company->id.'|'.$code;

        return $this->taxClasses[$key] ??= AssetTaxClass::query()->where('company_id', $ctx->company->id)->where('code', $code)->value('id');
    }
}
