import { formatDate } from '@/lib/format';
import { parseMoney } from '@/lib/money';

export type ColumnType = 'text' | 'money' | 'number' | 'date' | 'status';
export interface SortEntry {
    id: string;
    desc: boolean;
}
export interface TableUrlState {
    filters: Record<string, string>;
    sort: SortEntry[];
}

/**
 * Inline filter semantics (brief §4): text contains; money and numbers accept ">1000", "<=500", "1000..5000" or an exact amount; dates match
 * their displayed form ("sep 2026", "12 sep"); statuses match their words.
 */
export function matchesFilter(value: unknown, filter: string, type: ColumnType): boolean {
    const wanted = filter.trim();
    if (wanted === '') return true;
    const text = value === null || value === undefined ? '' : String(value);
    if (type === 'money' || type === 'number') {
        const amount = parseMoney(text);
        const range = wanted.match(/^(.+)\.\.(.+)$/);
        if (range) {
            const [from, to] = [parseMoney(range[1]), parseMoney(range[2])];
            return amount !== null && from !== null && to !== null && amount >= from && amount <= to;
        }
        const comparison = wanted.match(/^(>=|<=|>|<|=)?\s*(.+)$/);
        const operand = parseMoney(comparison?.[2] ?? '');
        if (amount !== null && operand !== null) {
            switch (comparison?.[1]) {
                case '>': return amount > operand;
                case '>=': return amount >= operand;
                case '<': return amount < operand;
                case '<=': return amount <= operand;
                default: return amount === operand;
            }
        }
        return text.toLowerCase().includes(wanted.toLowerCase());
    }
    if (type === 'date') {
        return `${text} ${formatDate(text)}`.toLowerCase().includes(wanted.toLowerCase());
    }
    if (type === 'status') {
        return text.replaceAll('_', ' ').toLowerCase().includes(wanted.toLowerCase());
    }
    return text.toLowerCase().includes(wanted.toLowerCase());
}

/** Filters and sort live in the URL (brief §4): `f.<column>=…` and `sort=-date,amount`. */
export function encodeTableState(state: TableUrlState, base = new URLSearchParams()): URLSearchParams {
    const params = new URLSearchParams(base);
    for (const key of [...params.keys()]) {
        if (key.startsWith('f.') || key === 'sort') params.delete(key);
    }
    for (const [column, value] of Object.entries(state.filters)) {
        if (value.trim() !== '') params.set(`f.${column}`, value);
    }
    if (state.sort.length) params.set('sort', state.sort.map((s) => `${s.desc ? '-' : ''}${s.id}`).join(','));
    return params;
}

export function decodeTableState(params: URLSearchParams): TableUrlState {
    const filters: Record<string, string> = {};
    for (const [key, value] of params.entries()) {
        if (key.startsWith('f.')) filters[key.slice(2)] = value;
    }
    const sort = (params.get('sort') ?? '').split(',').filter(Boolean).map((part) => ({ id: part.replace(/^-/, ''), desc: part.startsWith('-') }));
    return { filters, sort };
}
