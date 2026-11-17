/**
 * Money and date formatting functions for controls-grade accounting.
 * Rule: Money is stored as integer minor units (e.g. cents) and never floats.
 * UI displays tabular numbers, correct decimal units, and negative formatting.
 */

const CURRENCY_MINORS: Record<string, number> = {
  USD: 2,
  CHF: 2,
  KES: 2,
  SOS: 2,
  EUR: 2,
  GBP: 2,
  BDT: 2,
  AED: 2,
  SAR: 2,
  JPY: 0,
  // Three-decimal and zero-decimal currencies (ISO 4217), so typed and shown amounts line up with the server's minor units.
  KWD: 3,
  BHD: 3,
  OMR: 3,
  JOD: 3,
  TND: 3,
  LYD: 3,
  KRW: 0,
  VND: 0,
  UGX: 0,
  RWF: 0,
  XAF: 0,
  XOF: 0,
  CLP: 0,
  ISK: 0,
  PYG: 0,
};

const CURRENCY_SYMBOLS: Record<string, string> = {
  USD: '$',
  CHF: 'CHF',
  KES: 'KSh',
  SOS: 'Sh.So.',
  EUR: '€',
  GBP: '£',
  BDT: 'Tk',
  AED: 'Dhs',
  SAR: 'SR',
};

/**
 * A company's date and number format (plan 3c.26b), set once the live company is known. `null` keeps the demo's original en-US look.
 * Keys match the server's FormatPreferences (app/Domain/Shared). This changes the text only; money stays integer minor units. Typed
 * amounts are read with the same separators (`parseMoneyText`).
 */
export interface FormatPrefs {
  date: string;
  number: string;
}

const DATE_PARTS: Record<string, (d: string, m: string, y: string, mon: string) => string> = {
  dmy: (d, m, y) => `${d}/${m}/${y}`,
  mdy: (d, m, y) => `${m}/${d}/${y}`,
  ymd: (d, m, y) => `${y}-${m}-${d}`,
  d_mon_y: (d, _m, y, mon) => `${d} ${mon} ${y}`,
};

const NUMBER_SEPARATORS: Record<string, { group: string; decimal: string }> = {
  comma_dot: { group: ',', decimal: '.' },
  dot_comma: { group: '.', decimal: ',' },
  space_comma: { group: '\u202f', decimal: ',' },
  apostrophe_dot: { group: '\u2019', decimal: '.' },
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

let currentFormats: FormatPrefs | null = null;

export function setFormatPrefs(prefs: FormatPrefs | null): void {
  currentFormats = prefs && DATE_PARTS[prefs.date] && NUMBER_SEPARATORS[prefs.number] ? prefs : null;
}

export function getFormatPrefs(): FormatPrefs | null {
  return currentFormats;
}

/** Integer minor units as grouped text with the company's separators, or null when no company format is set. */
function groupedByCompany(absMinor: number, decimals: number): string | null {
  const sep = currentFormats ? NUMBER_SEPARATORS[currentFormats.number] : undefined;
  if (!sep) return null;
  let digits = String(Math.round(absMinor));
  if (decimals > 0) digits = digits.padStart(decimals + 1, '0');
  const whole = decimals > 0 ? digits.slice(0, -decimals) : digits;
  const fraction = decimals > 0 ? sep.decimal + digits.slice(-decimals) : '';
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, sep.group) + fraction;
}

export function getCurrencyMinorUnits(currency: string): number {
  return CURRENCY_MINORS[currency.toUpperCase()] ?? 2;
}

export function getCurrencySymbol(currency: string): string {
  return CURRENCY_SYMBOLS[currency.toUpperCase()] ?? currency;
}

/** "$1,250.00" but "KSh 1,250.00": sign-like symbols sit on the figure, letter codes get a space. */
function currencyPrefix(currency: string): string {
  const symbol = getCurrencySymbol(currency);
  return /\p{L}/u.test(symbol) ? `${symbol}\u00a0` : symbol;
}

/**
 * Formats integer minor units to localized currency string.
 * On reports: negative numbers are enclosed in parentheses: (1,250.00)
 * In grids: negative numbers can be formatted with minus sign or parentheses
 */
export function formatMoney(
  minorUnits: number | bigint | null | undefined,
  currency = 'USD',
  options: {
    parenthesesNegative?: boolean;
    showCurrency?: boolean;
    locale?: string;
  } = {}
): string {
  if (minorUnits === null || minorUnits === undefined) {
    return '—';
  }

  const {
    parenthesesNegative = false,
    showCurrency = true,
    locale = 'en-US',
  } = options;

  const minors = getCurrencyMinorUnits(currency);
  const numericValue = Number(minorUnits) / Math.pow(10, minors);
  const isNegative = numericValue < 0;
  const absValue = Math.abs(numericValue);

  const byCompany = groupedByCompany(Math.abs(Number(minorUnits)), minors);
  const formatter = new Intl.NumberFormat(locale, {
    minimumFractionDigits: minors,
    maximumFractionDigits: minors,
  });

  const formattedNum = byCompany ?? formatter.format(absValue);
  const symbol = showCurrency ? currencyPrefix(currency) : '';

  if (isNegative) {
    if (parenthesesNegative) {
      return `(${symbol}${formattedNum})`;
    }
    return `-${symbol}${formattedNum}`;
  }

  return `${symbol}${formattedNum}`;
}

const escapeRegExp = (v: string) => v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** The separators typed amounts are read with: the company's, or dot-decimal with commas (the demo's look) when none is set. */
function typedSeparators(): { decimal: string; groups: string[]; canonicalGroup: string } {
  const key = currentFormats?.number ?? 'comma_dot';
  const sep = NUMBER_SEPARATORS[key] ?? NUMBER_SEPARATORS.comma_dot;
  if (key === 'space_comma') return { decimal: sep.decimal, groups: [' ', ' ', ' '], canonicalGroup: ' ' };
  if (key === 'apostrophe_dot') return { decimal: sep.decimal, groups: ["'", '’'], canonicalGroup: "'" };
  return { decimal: sep.decimal, groups: [sep.group], canonicalGroup: sep.group };
}

/** Takes one currency symbol or code off the start and end of typed text. */
function withoutCurrency(text: string, currency: string): string {
  const tokens = [currency, getCurrencySymbol(currency)].filter((t) => t !== '').map(escapeRegExp).join('|');
  return text
    .replace(new RegExp(`^(?:${tokens})`, 'i'), '')
    .replace(/^\p{Sc}+/u, '')
    .trim()
    .replace(new RegExp(`(?:${tokens})$`, 'i'), '')
    .replace(/\p{Sc}+$/u, '')
    .trim();
}

/**
 * Reads typed money as integer minor units using the company's number format: its decimal mark and its group mark, so with
 * "1.234,56" both "1234,56" and "1.234,56" give 123456, and with "1,234.56" so do "1234.56" and "1,234.56". Digits are handled
 * as text, never as floats. Returns 0 for blank text and null for anything that is not an amount: stray characters, group marks
 * in the wrong place, two decimal marks, more decimals than the currency has (unless zeros: nothing is rounded), or a value beyond
 * safe integers. A leading or trailing minus, a real minus sign or parentheses make it negative.
 */
export function parseMoneyText(input: string, currency = 'USD'): number | null {
  let s = input.trim();
  if (s === '') return 0;

  let signs = 0;
  if (s.startsWith('(') && s.endsWith(')')) {
    signs += 1;
    s = s.slice(1, -1).trim();
  }
  if (/^[-−]/.test(s)) {
    signs += 1;
    s = s.slice(1).trim();
  }
  if (/[-−]$/.test(s)) {
    signs += 1;
    s = s.slice(0, -1).trim();
  }
  if (signs > 1) return null;
  s = withoutCurrency(s, currency);
  if (s === '') return null;

  const { decimal, groups, canonicalGroup } = typedSeparators();
  for (const g of groups) if (g !== canonicalGroup) s = s.split(g).join(canonicalGroup);
  const allowed = new RegExp(`^[0-9${escapeRegExp(decimal)}${escapeRegExp(canonicalGroup)}]+$`);
  if (!allowed.test(s)) return null;

  const parts = s.split(decimal);
  if (parts.length > 2) return null;
  const [intText, fracText = ''] = parts;
  if (!/^\d*$/.test(fracText)) return null;
  const grouped = new RegExp(`^\\d{1,3}(?:${escapeRegExp(canonicalGroup)}\\d{3})+$`);
  if (intText.includes(canonicalGroup) ? !grouped.test(intText) : !/^\d*$/.test(intText)) return null;
  const whole = intText.split(canonicalGroup).join('');
  if (whole === '' && fracText === '') return null;

  const minors = getCurrencyMinorUnits(currency);
  if (fracText.length > minors && /[^0]/.test(fracText.slice(minors))) return null;
  const digits = (whole + fracText.slice(0, minors).padEnd(minors, '0')).replace(/^0+(?=\d)/, '');
  const value = Number(digits);
  if (!Number.isSafeInteger(value)) return null;
  return signs === 1 && value !== 0 ? -value : value;
}

/** Integer minor units as editable text: the company's decimal mark, all the currency's decimals, no grouping. */
export function rawMoneyText(minorUnits: number, currency = 'USD'): string {
  const minors = getCurrencyMinorUnits(currency);
  const digits = String(Math.abs(Math.round(minorUnits))).padStart(minors + 1, '0');
  const whole = minors > 0 ? digits.slice(0, -minors) : digits;
  const text = minors > 0 ? `${whole}${typedSeparators().decimal}${digits.slice(-minors)}` : whole;
  return minorUnits < 0 ? `-${text}` : text;
}

/**
 * Parses user input (e.g. "$1,250.50", "-150", "(42.00)") into integer minor units with the company's separators; text that is not
 * an amount reads as 0. Use `parseMoneyText` when invalid input must be told apart from zero.
 */
export function parseMoneyToMinorUnits(input: string | number, currency = 'USD'): number {
  if (typeof input === 'number') return Math.round(input);
  if (!input || typeof input !== 'string') return 0;
  return parseMoneyText(input, currency) ?? 0;
}

/**
 * Formats an ISO date string (YYYY-MM-DD) into locale display date.
 */
export function formatDate(dateString: string | null | undefined, locale = 'en-US'): string {
  if (!dateString) return '—';
  try {
    const parts = dateString.split('T')[0].split('-');
    if (parts.length === 3) {
      const year = parseInt(parts[0], 10);
      const month = parseInt(parts[1], 10) - 1;
      const day = parseInt(parts[2], 10);
      if (currentFormats && year > 0 && month >= 0 && month < 12) {
        const pad = (n: number, w = 2) => String(n).padStart(w, '0');
        return DATE_PARTS[currentFormats.date](pad(day), pad(month + 1), pad(year, 4), MONTHS[month]);
      }
      const d = new Date(year, month, day);
      return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
      }).format(d);
    }
    return dateString;
  } catch {
    return dateString;
  }
}

/**
 * Formats an ISO datetime string into human readable date + time.
 */
export function formatDateTime(dateTimeString: string | null | undefined, locale = 'en-US'): string {
  if (!dateTimeString) return '—';
  try {
    const d = new Date(dateTimeString);
    if (currentFormats) {
      const pad = (n: number) => String(n).padStart(2, '0');
      const iso = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      return `${formatDate(iso)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }
    return new Intl.DateTimeFormat(locale, {
      year: 'numeric',
      month: 'short',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(d);
  } catch {
    return dateTimeString;
  }
}

/**
 * Relative time helper for activity logs and notifications
 */
export function formatRelativeTime(dateString: string): string {
  try {
    const d = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHours = Math.floor(diffMin / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return formatDate(dateString);
  } catch {
    return dateString;
  }
}

/**
 * Commercial rounding: ROUND_HALF_UP (away from zero, so -0.5 -> -1, 0.5 -> 1).
 * Standard for base-currency conversions and ledgers (D1).
 * Avoids JS Math.round error on negative numbers (Math.round(-0.5) is -0).
 */
/**
 * Short time-zone abbreviation for a company's IANA zone, e.g. "CET" for Europe/Zurich.
 * Used to give audit-log timestamps their missing time-zone context.
 */
export function timezoneAbbreviation(timezone: string, date: string | Date = new Date()): string {
  if (!timezone) return '';
  try {
    const parts = new Intl.DateTimeFormat('en', { timeZone: timezone, timeZoneName: 'short' }).formatToParts(new Date(date));
    return parts.find((p) => p.type === 'timeZoneName')?.value ?? timezone;
  } catch {
    return timezone;
  }
}

export function roundHalfUp(val: number): number {
  const sign = val < 0 ? -1 : 1;
  const abs = Math.abs(val);
  const eps = Number.EPSILON * 100;
  return sign * Math.round(abs + eps);
}
