import { formatMinor, parseMoney } from '@/lib/money';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Business date "2026-09-12" → "12 Sep 2026" (brief §4). Reads the calendar date from the string, so no timezone can shift it. */
export function formatDate(value: string | null | undefined): string {
    if (!value) return '';
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return value;
    return `${Number(match[3])} ${MONTHS[Number(match[2]) - 1]} ${match[1]}`;
}

/** Server-formatted amount → display: negatives in parentheses, no currency symbol (currency is shown once per table). */
export function formatMoney(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const minor = parseMoney(value);
    return minor === null ? value : formatMinor(minor);
}
