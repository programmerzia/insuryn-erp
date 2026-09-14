import { formatMinor, parseMoney } from '@/lib/money';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Business date "2026-09-12" → "12 Sep 2026" (brief §4). Reads the calendar date from the string, so no timezone can shift it. */
export function formatDate(value: string | null | undefined): string {
    if (!value) return '';
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return value;
    return `${Number(match[3])} ${MONTHS[Number(match[2]) - 1]} ${match[1]}`;
}

const LONG_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/**
 * Gap audit GA-36: a month from a business date or "2026-09" → "Sep 2026" (brief §4 short month, never the en-GB "Sept"), or "September 2026" with
 * `long`. Reads the text, so no timezone can move it to the previous month.
 */
export function formatMonth(value: string | null | undefined, style: 'short' | 'long' = 'short'): string {
    if (!value) return '';
    const match = value.match(/^(\d{4})-(\d{2})/);
    if (!match) return value;
    const month = Number(match[2]) - 1;
    return `${(style === 'long' ? LONG_MONTHS : MONTHS)[month] ?? match[2]} ${match[1]}`;
}

/** The time zone business timestamps are shown in: the insurer's (Bangladesh), not the browser's or UTC. */
export const DISPLAY_TIME_ZONE = 'Asia/Dhaka';

/**
 * Gap audit GA-36: a stored timestamp ("2026-09-14 07:43:57+00", ISO 8601) → "14 Sep 2026, 13:43" in Dhaka time. A bare date is shown as a date;
 * text that is not a timestamp is returned unchanged.
 */
export function formatDateTime(value: string | null | undefined, timeZone: string = DISPLAY_TIME_ZONE): string {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value);
    const normalised = value.trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
    const date = new Date(normalised);
    if (!/^\d{4}-\d{2}-\d{2}T/.test(normalised) || Number.isNaN(date.getTime())) return value;
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(date)
            .map((p) => [p.type, p.value]),
    );
    return `${formatDate(`${parts.year}-${parts.month}-${parts.day}`)}, ${parts.hour}:${parts.minute}`;
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
