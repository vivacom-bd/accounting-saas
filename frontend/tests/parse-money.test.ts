import { afterEach, describe, expect, it } from 'vitest';
import { formatMoney, getCurrencyMinorUnits, parseMoneyText, parseMoneyToMinorUnits, rawMoneyText, setFormatPrefs } from '../src/lib/format';

afterEach(() => setFormatPrefs(null));

const use = (number: string) => setFormatPrefs({ date: 'ymd', number });

describe('typed money follows the company number format (plan 3c.26c)', () => {
  it('reads "1.234,56" and "1234,56" as 123456 when the company writes commas for decimals', () => {
    use('dot_comma');
    expect(parseMoneyText('1.234,56', 'USD')).toBe(123456);
    expect(parseMoneyText('1234,56', 'USD')).toBe(123456);
    expect(parseMoneyText('1234', 'USD')).toBe(123400);
    expect(parseMoneyText('0,5', 'USD')).toBe(50);
    expect(parseMoneyText(',5', 'USD')).toBe(50);
    expect(parseMoneyText('12,', 'USD')).toBe(1200);
  });

  it('reads "1,234.56" and "1234.56" as 123456 when the company writes dots for decimals', () => {
    use('comma_dot');
    expect(parseMoneyText('1,234.56', 'USD')).toBe(123456);
    expect(parseMoneyText('1234.56', 'USD')).toBe(123456);
    expect(parseMoneyText('$1,250.50', 'USD')).toBe(125050);
  });

  it('keeps the original dot-and-comma reading when no company format is set', () => {
    expect(parseMoneyText('1,234.56', 'USD')).toBe(123456);
    expect(parseMoneyToMinorUnits('1,234.56', 'USD')).toBe(123456);
    expect(parseMoneyToMinorUnits('(42.00)', 'USD')).toBe(-4200);
  });

  it.each([
    ['space_comma', '1 234 567,89'],
    ['space_comma', '1 234 567,89'],
    ['space_comma', '1234567,89'],
    ['apostrophe_dot', '1’234’567.89'],
    ['apostrophe_dot', "1'234'567.89"],
    ['apostrophe_dot', '1234567.89'],
    ['dot_comma', '1.234.567,89'],
    ['comma_dot', '1,234,567.89'],
  ])('reads %s text %j as 123456789', (number, text) => {
    use(number);
    expect(parseMoneyText(text, 'USD')).toBe(123456789);
  });

  it('round-trips what formatMoney writes, for every company format', () => {
    for (const number of ['comma_dot', 'dot_comma', 'space_comma', 'apostrophe_dot']) {
      use(number);
      for (const minor of [0, 5, 99, 100, 123456, 100000000, 123456789]) {
        expect(parseMoneyText(formatMoney(minor, 'USD', { showCurrency: false }), 'USD')).toBe(minor);
        expect(parseMoneyText(rawMoneyText(minor, 'USD'), 'USD')).toBe(minor);
      }
    }
  });

  it('keeps the sign: minus, a real minus sign, parentheses, or a trailing minus', () => {
    use('dot_comma');
    expect(parseMoneyText('-1.234,56', 'USD')).toBe(-123456);
    expect(parseMoneyText('−1234,56', 'USD')).toBe(-123456);
    expect(parseMoneyText('(1.234,56)', 'USD')).toBe(-123456);
    expect(parseMoneyText('1234,56-', 'USD')).toBe(-123456);
    expect(parseMoneyText('-$5,00', 'USD')).toBe(-500);
    expect(parseMoneyText('-0,05', 'USD')).toBe(-5);
  });

  it('uses the currency decimals: none for JPY, three for KWD and BHD', () => {
    expect(getCurrencyMinorUnits('JPY')).toBe(0);
    expect(getCurrencyMinorUnits('KWD')).toBe(3);
    expect(getCurrencyMinorUnits('BHD')).toBe(3);
    use('dot_comma');
    expect(parseMoneyText('1.500.000', 'JPY')).toBe(1500000);
    expect(parseMoneyText('1500', 'JPY')).toBe(1500);
    expect(parseMoneyText('1,234', 'KWD')).toBe(1234);
    expect(parseMoneyText('1.234,567', 'KWD')).toBe(1234567);
    expect(parseMoneyText('12,5', 'BHD')).toBe(12500);
    use('comma_dot');
    expect(parseMoneyText('1,234.567', 'KWD')).toBe(1234567);
    expect(parseMoneyText('0.001', 'BHD')).toBe(1);
  });

  it('never rounds: more decimals than the currency has is refused, unless they are zeros', () => {
    use('dot_comma');
    expect(parseMoneyText('1,234', 'USD')).toBeNull();
    expect(parseMoneyText('1,230', 'USD')).toBe(123);
    expect(parseMoneyText('1500,5', 'JPY')).toBeNull();
    expect(parseMoneyText('1500,0', 'JPY')).toBe(1500);
    expect(parseMoneyText('1.234,5678', 'KWD')).toBeNull();
  });

  it('refuses text that is not an amount, and group marks in the wrong place', () => {
    use('dot_comma');
    for (const bad of ['abc', '1e5', '1,2,3', '12.34', '1.23.456', '1.2345', ',', '.', '1,,5', '--5', '5-5', '1 2', '()', '1,5,5']) {
      expect(parseMoneyText(bad, 'USD'), bad).toBeNull();
    }
    use('comma_dot');
    for (const bad of ['12,34', '1,2345', '1.2.3', '1,23,456']) expect(parseMoneyText(bad, 'USD'), bad).toBeNull();
  });

  it('reads blank as zero and refuses amounts beyond safe integers', () => {
    expect(parseMoneyText('', 'USD')).toBe(0);
    expect(parseMoneyText('   ', 'USD')).toBe(0);
    expect(parseMoneyText('99999999999999999999', 'USD')).toBeNull();
  });

  it('uses no floating point: large and tricky values stay exact', () => {
    use('comma_dot');
    expect(parseMoneyText('1.15', 'USD')).toBe(115);
    expect(parseMoneyText('1.005', 'KWD')).toBe(1005);
    expect(parseMoneyText('20,000,000,000.07', 'USD')).toBe(2000000000007);
    expect(parseMoneyText('0.29', 'USD')).toBe(29);
  });

  it('writes the editing text with the company decimal and no grouping', () => {
    use('dot_comma');
    expect(rawMoneyText(123456, 'USD')).toBe('1234,56');
    expect(rawMoneyText(-5, 'USD')).toBe('-0,05');
    expect(rawMoneyText(1500, 'JPY')).toBe('1500');
    expect(rawMoneyText(1234567, 'KWD')).toBe('1234,567');
    use('comma_dot');
    expect(rawMoneyText(123456, 'USD')).toBe('1234.56');
    setFormatPrefs(null);
    expect(rawMoneyText(100, 'USD')).toBe('1.00');
  });

  it('keeps the legacy number input as whole minor units', () => {
    expect(parseMoneyToMinorUnits(1234.4, 'USD')).toBe(1234);
  });

  it('reads invalid text as zero in the legacy helper', () => {
    use('dot_comma');
    expect(parseMoneyToMinorUnits('abc', 'USD')).toBe(0);
    expect(parseMoneyToMinorUnits('1.234,56', 'USD')).toBe(123456);
  });
});
