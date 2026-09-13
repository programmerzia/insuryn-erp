const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

function iso(year: number, month: number, day: number): string | null {
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function fullYear(text: string | undefined, today: Date): number {
    if (text === undefined) return today.getFullYear();
    const year = Number(text);
    return text.length <= 2 ? 2000 + year : year;
}

/**
 * Keyboard date entry (brief §4): `t` today, `+3` / `-2` days from today, "12 Sep 2026", "12 sep" (this year), "2026-09-12",
 * "12/09/2026" and "1.1.27" (day first, as written in Bangladesh). Returns an ISO date or null.
 */
export function parseDateInput(text: string, today: Date = new Date()): string | null {
    const value = text.trim().toLowerCase();
    if (value === '') return null;
    if (value === 't' || value === 'today') return iso(today.getFullYear(), today.getMonth() + 1, today.getDate());
    const relative = value.match(/^([+-])(\d{1,4})$/);
    if (relative) {
        const date = new Date(today.getFullYear(), today.getMonth(), today.getDate() + (relative[1] === '-' ? -1 : 1) * Number(relative[2]));
        return iso(date.getFullYear(), date.getMonth() + 1, date.getDate());
    }
    const isoMatch = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (isoMatch) return iso(Number(isoMatch[1]), Number(isoMatch[2]), Number(isoMatch[3]));
    const numeric = value.match(/^(\d{1,2})[/.-](\d{1,2})(?:[/.-](\d{2}|\d{4}))?$/);
    if (numeric) return iso(fullYear(numeric[3], today), Number(numeric[2]), Number(numeric[1]));
    const named = value.match(/^(\d{1,2})\s+([a-z]{3,9})\.?(?:,?\s+(\d{2}|\d{4}))?$/);
    if (named) {
        const month = MONTHS.indexOf((named[2] ?? '').slice(0, 3));
        return month === -1 ? null : iso(fullYear(named[3], today), month + 1, Number(named[1]));
    }
    return null;
}
