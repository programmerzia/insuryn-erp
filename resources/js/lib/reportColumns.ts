/**
 * Gap audit GA-06 / GA-34: report column widths sized to what they hold. Every text column used to be 220 px and every figure 140 px, so the
 * premium register's money columns were pushed off a 1440 px screen by short codes, and headers like "Earned premium (BDT)" were cut off.
 * A column is as wide as its header (with the currency suffix and the sort and filter buttons) or its longest value, within limits.
 */
const CHAR_PX = 7.5;
const HEADER_CHROME_PX = 44;
const CELL_PADDING_PX = 28;

export function reportColumnWidth(header: string, values: (string | number | null | undefined)[], kind: 'text' | 'number' | 'money' | 'date'): number {
    const longest = values.slice(0, 500).reduce<number>((max, v) => Math.max(max, v === null || v === undefined ? 0 : String(v).length), 0);
    const byHeader = Math.ceil(header.length * CHAR_PX) + HEADER_CHROME_PX;
    const byValue = kind === 'date' ? 110 : Math.ceil(longest * CHAR_PX) + CELL_PADDING_PX;
    const [min, max] = kind === 'text' ? [90, 280] : [96, 220];
    return Math.min(max, Math.max(min, byHeader, byValue));
}
