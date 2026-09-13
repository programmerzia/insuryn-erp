import type { ColumnType } from '@/lib/table-state';

/** A DataTable column (brief §4 tables). `value` feeds display, sort, filter, totals and export. */
export interface DataColumn<T> {
    id: string;
    header: string;
    type?: ColumnType;
    value: (row: T) => string | number | null | undefined;
    /** Link target for the cell; Ctrl+click pins it as a tab. */
    href?: (row: T) => string | null | undefined;
    /** Tab title when pinned (defaults to the cell text). */
    pinTitle?: (row: T) => string;
    width?: number;
    /** Money column with a footer total; the first one also sums the selection in the status bar. */
    total?: boolean;
    /** Filter with a list of values instead of free text. */
    filterOptions?: string[];
    hideable?: boolean;
    /** Secondary text colour (references, descriptions). */
    muted?: boolean;
}

export interface ServerPage {
    current: number;
    last: number;
    total: number;
    go: (page: number) => void;
}
