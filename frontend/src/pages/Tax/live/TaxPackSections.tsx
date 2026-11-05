import React from 'react';
import { fmt } from '@/pages/Reports/live/parts';

type Money = { amount: number; currency: string };

export interface PackSection {
  key: string;
  group: 'output' | 'input' | 'adjustments' | 'credits' | 'net';
  label: string;
  note: string | null;
  effect: 'add' | 'subtract' | 'none';
  manual: boolean;
  net: Money | null;
  tax: Money | null;
  amount: Money;
}

export interface PackReturn {
  key: string;
  version: number;
  name: string;
  reverse_charge_mode: 'gross' | 'net_non_creditable';
  notes: string[];
  sections: PackSection[];
  net: { payable: Money; carried_forward: Money };
  unmapped: Array<{ side: string; code: string | null; net: Money; tax: Money }>;
}

const GROUPS: Array<[PackSection['group'], string]> = [
  ['output', 'Output tax'],
  ['input', 'Input tax'],
  ['adjustments', 'Adjustments'],
  ['credits', 'Credits'],
];

/** A country pack's return sections as the server computed them. Labels are the pack's own; nothing here is added up. */
export const TaxPackSections: React.FC<{ pack: PackReturn; currency: string }> = ({ pack, currency }) => {
  const money = fmt(currency);
  return (
    <div className="flex flex-col gap-3" data-testid="pack-return">
      {GROUPS.map(([group, title]) => {
        const rows = pack.sections.filter((s) => s.group === group);
        if (rows.length === 0) return null;
        return (
          <div key={group}>
            <div className="pb-1 text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">{title}</div>
            <table className="w-full text-[12px]">
              <tbody>
                {rows.map((s) => (
                  <tr key={s.key} className="border-b border-border/60 align-top" data-section={s.key}>
                    <td className="py-1 text-text-primary">
                      {s.label}
                      {s.manual && <span className="ml-2 text-text-muted">entered by hand</span>}
                      {s.note && <div className="text-[11px] text-text-muted">{s.note}</div>}
                    </td>
                    <td className="w-28 py-1 text-right tabular-nums text-text-secondary">{s.net ? money(s.net.amount) : ''}</td>
                    <td className="w-28 py-1 text-right tabular-nums text-text-primary">{money(s.amount.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        );
      })}
      <div className="flex justify-between border-t border-border pt-2 text-[13px] font-semibold">
        <span>{pack.net.carried_forward.amount > 0 ? 'Carried forward' : 'Payable'}</span>
        <span className="tabular-nums" data-testid="pack-net">{money(pack.net.carried_forward.amount > 0 ? pack.net.carried_forward.amount : pack.net.payable.amount)}</span>
      </div>
      {pack.unmapped.length > 0 && (
        <p role="status" className="rounded-md border border-warning/50 bg-warning/10 p-2 text-[12px] text-text-primary" data-testid="pack-unmapped">
          Some tax codes with activity are not mapped to a section: {pack.unmapped.map((u) => u.code ?? 'no tax code').join(', ')}. They are in the totals per tax code but not in these sections.
        </p>
      )}
      {pack.notes.map((n) => (
        <p key={n} className="text-[11px] text-text-muted">{n}</p>
      ))}
    </div>
  );
};
