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
/** Slice R8: a PDF generated from a template; `url` downloads its stored document. */
export interface GeneratedDocumentRow {
    id: string;
    title: string;
    number: string | null;
    version: number;
    locale: string;
    template_version: number;
    rendered_by: string;
    rendered_at: string;
    reference: string;
    sha256: string;
    size_bytes: number;
    url: string;
}

/** Slice R8: what the Documents tab may generate for the object (`url` posts it) and the versions generated so far. */
export interface DocumentGeneration {
    url: string | null;
    actions: { label: string; template_code: string; object_id: string | null }[];
    locales: { value: string; label: string }[];
    history: GeneratedDocumentRow[];
}

export interface StoredDocumentRow {
    id: string;
    name: string;
    mime: string;
    size_bytes: number;
    description: string | null;
    uploaded_by: string;
    uploaded_at: string;
    url: string;
}
