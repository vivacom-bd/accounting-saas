<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Compares a filed return snapshot with the return as it stands now (D15 item 13, "changed since filing"). Pure PHP. Both are the
 * tax return report arrays; every money figure is flattened to a labelled integer and the figures that differ are listed with the
 * difference (current − filed). Anything that exists on only one side counts as 0 on the other.
 */
final class TaxReturnDiff
{
    /**
     * @param  array<string, mixed>  $filed
     * @param  array<string, mixed>  $current
     * @return list<array{key: string, label: string, filed: int, current: int, difference: int}>
     */
    public static function compare(array $filed, array $current): array
    {
        $a = self::flatten($filed);
        $b = self::flatten($current);
        $out = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            $was = $a[$key]['amount'] ?? 0;
            $now = $b[$key]['amount'] ?? 0;
            if ($was !== $now) {
                $out[] = ['key' => $key, 'label' => $b[$key]['label'] ?? $a[$key]['label'], 'filed' => $was, 'current' => $now, 'difference' => $now - $was];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array{label: string, amount: int}>
     */
    public static function flatten(array $report): array
    {
        $out = [];
        $put = function (string $key, string $label, mixed $money) use (&$out): void {
            $out[$key] = ['label' => $label, 'amount' => is_array($money) ? (int) ($money['amount'] ?? 0) : 0];
        };
        foreach (['sales' => 'Sales', 'purchases' => 'Purchases'] as $side => $title) {
            foreach ((array) ($report[$side]['rows'] ?? []) as $row) {
                $id = $row['tax_code']['id'] ?? 'none';
                $name = $title.' '.($row['tax_code']['code'] ?? 'no tax code');
                $put("{$side}:{$id}:net", "{$name} net", $row['net'] ?? null);
                $put("{$side}:{$id}:tax", "{$name} tax", $row['tax'] ?? null);
            }
        }
        foreach ((array) ($report['reverse_charge']['rows'] ?? []) as $row) {
            $id = $row['tax_code']['id'] ?? 'none';
            $name = 'Reverse charge '.($row['tax_code']['code'] ?? 'no tax code');
            foreach (['base', 'output', 'input'] as $part) {
                $put("reverse_charge:{$id}:{$part}", "{$name} {$part}", $row[$part] ?? null);
            }
        }
        $put('net_tax_payable', 'Net tax payable', $report['net_tax_payable'] ?? null);
        foreach ((array) ($report['pack']['sections'] ?? []) as $section) {
            $key = (string) ($section['key'] ?? '');
            foreach (['net', 'tax', 'amount'] as $part) {
                if (isset($section[$part])) {
                    $put("section:{$key}:{$part}", (string) ($section['label'] ?? $key).' '.$part, $section[$part]);
                }
            }
        }
        if (isset($report['pack']['net'])) {
            $put('section_net:payable', 'Net payable (pack return)', $report['pack']['net']['payable'] ?? null);
            $put('section_net:carried_forward', 'Carried forward (pack return)', $report['pack']['net']['carried_forward'] ?? null);
        }

        return $out;
    }
}
