<?php

declare(strict_types=1);

namespace App\Services\Tax;

use LogicException;

/**
 * The country packs shipped with the application: data files in `resources/country-packs`, one array per pack (plan 3c.22). The core
 * never asks which country a company is in; it asks which pack the company applied. A malformed pack is a programming error and
 * fails loudly, so a test over every shipped pack is enough.
 */
final class CountryPacks
{
    public const array EFFECTS = ['add', 'subtract', 'none'];

    public const array SOURCES = ['codes', 'reverse_charge_output', 'reverse_charge_input', 'manual'];

    public const array GROUPS = ['output', 'input', 'adjustments', 'credits'];

    public const array SIDES = ['sales', 'purchases', 'reverse_charge'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $packs = null;

    public function __construct(private readonly string $directory = '') {}

    /** @return array<string, array<string, mixed>> keyed by pack key */
    public function all(): array
    {
        if ($this->packs === null) {
            $dir = $this->directory !== '' ? $this->directory : resource_path('country-packs');
            $packs = [];
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                /** @var array<string, mixed> $pack */
                $pack = require $file;
                $this->validate($pack, $file);
                $packs[(string) $pack['key']] = $pack;
            }
            ksort($packs);
            $this->packs = $packs;
        }

        return $this->packs;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $pack */
    private function validate(array $pack, string $file): void
    {
        foreach (['key', 'version', 'name', 'authority', 'filing', 'reverse_charge_mode', 'tax_codes', 'return_sections', 'sources'] as $field) {
            if (! array_key_exists($field, $pack)) {
                throw new LogicException("Country pack {$file} lacks {$field}.");
            }
        }
        if (! in_array($pack['reverse_charge_mode'], ['gross', 'net_non_creditable'], true)) {
            throw new LogicException("Country pack {$pack['key']} has an unknown reverse charge mode.");
        }
        $sources = array_column((array) $pack['sources'], 'id');
        $codes = [];
        foreach ((array) $pack['tax_codes'] as $c) {
            $codes[(string) $c['code']] = true;
            if ($c['rates'] === []) {
                throw new LogicException("Pack tax code {$c['code']} has no rate.");
            }
            foreach ($c['rates'] as $r) {
                if (! in_array($r['source'], $sources, true) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['as_of']) || ! preg_match('/^(0(\.\d{1,6})?|1(\.0{1,6})?)$/', (string) $r['rate'])) {
                    throw new LogicException("Pack tax code {$c['code']} has a rate without a known source, an as-of date or a valid fraction.");
                }
            }
        }
        $seen = [];
        foreach ((array) $pack['return_sections'] as $s) {
            if (isset($seen[$s['key']]) || ! in_array($s['effect'], self::EFFECTS, true) || ! in_array($s['source'], self::SOURCES, true) || ! in_array($s['group'], self::GROUPS, true)) {
                throw new LogicException("Pack return section {$s['key']} is malformed or repeated.");
            }
            $seen[$s['key']] = true;
            foreach ($s['map'] as [$side, $code]) {
                if (! in_array($side, self::SIDES, true) || ! isset($codes[$code])) {
                    throw new LogicException("Pack return section {$s['key']} maps an unknown side or tax code.");
                }
            }
        }
        foreach ((array) ($pack['depreciation_classes'] ?? []) as $d) {
            if (! in_array($d['source'], $sources, true) || ! is_int($d['annual_bp'])) {
                throw new LogicException("Pack depreciation class {$d['key']} needs a known source and an integer rate.");
            }
        }
    }
}
