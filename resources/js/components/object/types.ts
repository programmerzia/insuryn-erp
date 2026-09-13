export interface TimelineEntry {
    sentence: string;
    date: string;
    reason: string | null;
}
export interface AccountingJournal {
    id: string;
    number: string | null;
    event: string | null;
    date: string;
    status: string;
    lines: { account: string; name: string; debit: string | null; credit: string | null; role?: string | null }[];
}
export interface AuditRow {
    action: string;
    by: string;
    at: string;
    reason: string | null;
    changes: { field: string; before: string | null; after: string | null }[];
}
