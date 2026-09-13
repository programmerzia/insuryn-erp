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

/** File size for people: "512 B", "12.4 KB", "2.1 MB" (1 KB = 1,024 bytes, as the upload limit is counted). */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    return `${value >= 100 ? Math.round(value) : Math.round(value * 10) / 10} ${units[unit]}`;
}
