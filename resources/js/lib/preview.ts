import { HttpError, requestJson } from '@/lib/http';

/** Response of a money-moving form submitted with X-Journal-Preview (App\Http\Preview\PreviewJournal). */
export interface PreviewLine {
    account: string;
    name: string;
    debit: string | null;
    credit: string | null;
}
export interface PreviewJournal {
    event: string;
    date: string;
    lines: PreviewLine[];
    totals: { debit: string; credit: string };
}
export interface PreviewResult {
    journals: PreviewJournal[];
    failures: { event: string; reason: string }[];
    posts: boolean;
}

export type PreviewOutcome = { ok: true; result: PreviewResult } | { ok: false; errors: Record<string, string> };

/** Asks the server what posting the form would produce. Refusals come back as field errors (and `form` for business rules). */
export async function previewJournal(url: string, data: Record<string, unknown>): Promise<PreviewOutcome> {
    try {
        const result = await fetchPreview(url, data);
        return { ok: true, result };
    } catch (error) {
        if (error instanceof HttpError && typeof error.body === 'object' && error.body !== null && 'errors' in error.body) {
            const errors = (error.body as { errors: Record<string, string | string[] | null> }).errors;
            return { ok: false, errors: Object.fromEntries(Object.entries(errors).filter(([, v]) => v !== null).map(([k, v]) => [k, Array.isArray(v) ? (v[0] ?? '') : String(v)])) };
        }
        return { ok: false, errors: { form: 'The preview could not be prepared. Check your connection and try again.' } };
    }
}

async function fetchPreview(url: string, data: Record<string, unknown>): Promise<PreviewResult> {
    const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1];
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Journal-Preview': '1', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '' },
        body: JSON.stringify(data),
    });
    const body: unknown = await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, body);
    return body as PreviewResult;
}

export { requestJson };
