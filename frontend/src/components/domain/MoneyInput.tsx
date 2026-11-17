import React, { useState, useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import {
  formatMoney,
  getCurrencySymbol,
  parseMoneyText,
  rawMoneyText,
} from '@/lib/format';

export interface MoneyInputProps {
  value: number; // in integer minor units (e.g. 100000 = $1,000.00)
  onChange: (value: number) => void;
  currency?: string;
  disabled?: boolean;
  readOnly?: boolean;
  error?: string;
  placeholder?: string;
  className?: string;
  id?: string;
  name?: string;
  autoFocus?: boolean;
  onBlur?: () => void;
  onFocus?: () => void;
  onKeyDown?: (e: React.KeyboardEvent<HTMLInputElement>) => void;
  allowNegative?: boolean;
  size?: 'sm' | 'md';
  ariaLabel?: string;
}

export const MoneyInput: React.FC<MoneyInputProps> = ({
  value,
  onChange,
  currency = 'USD',
  disabled = false,
  readOnly = false,
  error,
  placeholder,
  className,
  id,
  name,
  autoFocus = false,
  onBlur,
  onFocus,
  onKeyDown,
  allowNegative = true,
  size = 'md',
  ariaLabel,
}) => {
  const [isFocused, setIsFocused] = useState(false);
  const symbol = getCurrencySymbol(currency);
  const inputRef = useRef<HTMLInputElement>(null);

  // Blurred text uses the company's separators; editing text uses its decimal mark and no grouping (plan 3c.26c).
  const formatBlurredValue = (units: number): string => {
    if (units === 0 && !value) return '';
    return formatMoney(units, currency, { showCurrency: false, parenthesesNegative: true });
  };

  const formatRawValue = (units: number): string => {
    if (units === 0 && !value) return '';
    return rawMoneyText(units, currency);
  };

  // Text that is not an amount is told apart from zero: it is not passed on, and the field says so.
  const [invalid, setInvalid] = useState(false);

  const [displayValue, setDisplayValue] = useState(() => formatBlurredValue(value));

  // Sync external value changes when not actively typing
  useEffect(() => {
    if (!isFocused) {
      setDisplayValue(formatBlurredValue(value));
    }
  }, [value, isFocused, currency]);

  const handleFocus = (e: React.FocusEvent<HTMLInputElement>) => {
    setIsFocused(true);
    setDisplayValue(formatRawValue(value));
    onFocus?.();
  };

  const handleBlur = () => {
    setIsFocused(false);
    const parsed = parseMoneyText(displayValue, currency);
    // Invalid text goes back to the last good amount rather than becoming a different one.
    const finalVal = parsed === null ? value : allowNegative ? parsed : Math.max(0, parsed);
    if (parsed !== null) onChange(finalVal);
    setInvalid(false);
    setDisplayValue(formatBlurredValue(finalVal));
    onBlur?.();
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const raw = e.target.value;
    setDisplayValue(raw);
    const parsed = parseMoneyText(raw, currency);
    setInvalid(parsed === null);
    if (parsed === null) return;
    onChange(allowNegative ? parsed : Math.max(0, parsed));
  };

  const isNegative = value < 0;

  return (
    <div className="relative inline-flex items-center w-full">
      {/* Currency prefix tag */}
      <span
        className={cn(
          'absolute left-2.5 font-mono select-none pointer-events-none transition-colors text-xs',
          disabled ? 'text-ink-300' : 'text-ink-500'
        )}
      >
        {symbol}
      </span>

      <input
        ref={inputRef}
        id={id}
        name={name}
        type="text"
        inputMode="decimal"
        autoFocus={autoFocus}
        disabled={disabled}
        readOnly={readOnly}
        value={displayValue}
        placeholder={placeholder ?? formatMoney(0, currency, { showCurrency: false })}
        aria-label={ariaLabel}
        onChange={handleChange}
        onFocus={handleFocus}
        onBlur={handleBlur}
        onKeyDown={onKeyDown}
        aria-invalid={error || invalid ? true : undefined}
        className={cn(
          'w-full bg-surface text-ink-900 border rounded-sm font-mono text-num text-right pl-7 pr-3 transition-colors',
          'focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:ring-offset-1',
          size === 'sm' ? 'h-8 text-xs py-1' : 'h-9 text-sm py-1.5',
          error || invalid
            ? 'border-danger focus:border-danger focus:ring-danger/20 text-danger'
            : isNegative
            ? 'border-ink-200 text-danger'
            : 'border-ink-200 hover:border-ink-300',
          disabled && 'bg-ink-100 text-ink-500 opacity-60 cursor-not-allowed',
          readOnly && 'bg-transparent border-transparent shadow-none cursor-default pr-0',
          className
        )}
      />
    </div>
  );
};
