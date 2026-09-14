import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import { sharedBusinessToday } from '@/lib/businessToday';
import { dateWithin, depositDraft, refundAmount } from '@/lib/drawerDefaults';

/**
 * Gap fix GA-19 (X2 missed these): drawers that record something happening now start at the company's today and with the amount they are about;
 * dates only the customer or a certificate knows stay empty.
 */
describe('money drawer defaults', () => {
    it('reads the shared business today and ignores anything that is not an ISO date', () => {
        expect(sharedBusinessToday({ businessToday: '2026-09-14' })).toBe('2026-09-14');
        expect(sharedBusinessToday({ businessToday: '14 Sep 2026' })).toBeNull();
        expect(sharedBusinessToday({})).toBeNull();
        expect(sharedBusinessToday(null)).toBeNull();
    });

    it('prefills a deposit with the cash the agent has not deposited yet, dated today', () => {
        const rows = [{ agent_id: 'a1', agent_code: 'AG-001', undeposited: '12500.00' }, { agent_id: 'a2', agent_code: 'AG-002', undeposited: '0.00' }];
        expect(depositDraft(rows, 'a1', '2026-09-14')).toEqual({ agent_id: 'a1', amount: '12500.00', deposited_on: '2026-09-14' });
        expect(depositDraft(rows, 'a2', '2026-09-14').amount).toBe('');
        expect(depositDraft(rows, '', '2026-09-14')).toEqual({ agent_id: '', amount: '', deposited_on: '2026-09-14' });
        expect(depositDraft([{ agent_id: 'a3', agent_code: 'AG-003', undeposited: '-10.00' }], 'a3', '2026-09-14').amount).toBe('');
    });

    it('prefills a refund request with what is still refundable on the policy', () => {
        expect(refundAmount([{ policy_id: 'p1', available: '22784.38' }], 'p1')).toBe('22784.38');
        expect(refundAmount([{ policy_id: 'p1', available: '22784.38' }], 'p2')).toBe('');
    });

    it('keeps an endorsement or cancellation date inside the cover', () => {
        expect(dateWithin('2026-09-14', '2026-09-01', '2027-08-31')).toBe('2026-09-14');
        expect(dateWithin('2026-09-14', '2026-10-01', '2027-09-30')).toBe('2026-10-01');
        expect(dateWithin('2027-09-14', '2026-09-01', '2027-08-31')).toBe('2027-08-31');
    });
});

/**
 * The sweep: every `DateInput` bound to a form field whose script starts it as '' must be a date nobody can know for the user. New empty "now" dates
 * fail here until they get today or are listed with the reason they stay empty.
 */
const root = join(__dirname, '..');
const STAYS_EMPTY: Record<string, string> = {
    'pages/accounting/Imports.vue:form.opening_date': 'the opening balance date comes from the migrated books',
    'pages/products/Index.vue:versionForm.effective_to': 'an optional end date; empty means open-ended',
    'pages/rating/plans/Index.vue:planForm.effective_to': 'an optional end date; empty means open-ended',
    'pages/rating/plans/Index.vue:versionForm.effective_from': 'empty keeps the copied version dates (hint)',
    'pages/rating/plans/Index.vue:versionForm.effective_to': 'empty keeps the copied version dates (hint)',
    'pages/rating/plans/Show.vue:versionForm.effective_from': 'empty keeps this version dates (hint)',
    'pages/rating/plans/Show.vue:versionForm.effective_to': 'empty keeps this version dates (hint)',
    'pages/rating/plans/Show.vue:dutyForm.effective_to': 'an optional end date; empty means open-ended',
    'pages/receipts/Create.vue:form.cheque_date': 'written on the customer’s cheque',
    'pages/distribution/producers/Show.vue:licence.issued_on': 'printed on the licence certificate',
    'pages/distribution/producers/Show.vue:licence.expires_on': 'printed on the licence certificate',
    'pages/distribution/producers/Index.vue:form.joined_on': 'optional; the day the producer joined is theirs to give',
    'pages/policies/Create.vue:form.inception': 'the customer chooses when cover starts (legacy unrated quote form)',
};

function vueFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return name === 'tests' ? [] : vueFiles(path);
        return name.endsWith('.vue') ? [path] : [];
    });
}

/** The initialiser statement of `owner` (`const owner = …;`) — the text up to the first semicolon that ends a line. */
function initialiser(source: string, owner: string): string | null {
    const start = source.search(new RegExp(`const ${owner}\\s*=`));
    if (start === -1) return null;
    const end = source.slice(start).search(/;[ \t]*(\/\/[^\n]*)?\n/);
    return source.slice(start, end === -1 ? undefined : start + end);
}

function emptyAtStart(source: string, model: string): boolean {
    const parts = model.split('.');
    const owner = parts[0] ?? '';
    const key = parts[parts.length - 1] ?? '';
    const init = initialiser(source, owner);
    if (init === null) return false;
    // A drawer that sets its starting values when it opens (`owner.form.defaults({ key: today })`, claims/Show) is not empty.
    for (const call of source.matchAll(new RegExp(`\\b${owner}(\\.form)?\\.defaults\\(\\{([^}]*)\\}`, 'g'))) {
        if (new RegExp(`\\b${key}:\\s*(?!'')\\S`).test(call[2] ?? '')) return false;
    }
    if (parts.length === 1) return /ref(<[^>]*>)?\(''\)/.test(init);
    return new RegExp(`\\b${key}:\\s*''`).test(init);
}

describe('date fields that record something happening now', () => {
    it('start at today unless the date is one only the customer or a document knows', () => {
        const offenders: string[] = [];
        const listed = new Set<string>();
        for (const file of vueFiles(join(root, 'pages'))) {
            const source = readFileSync(file, 'utf8');
            for (const match of source.matchAll(/<DateInput\b[^>]*\sv-model="([A-Za-z_.]+)"/g)) {
                const id = `${relative(root, file)}:${match[1]}`;
                if (!emptyAtStart(source, match[1] ?? '')) continue;
                if (id in STAYS_EMPTY) listed.add(id);
                else offenders.push(id);
            }
        }
        expect(offenders).toEqual([]);
        // The list names only fields that still start empty, so a fixed field leaves it.
        expect(Object.keys(STAYS_EMPTY).filter((id) => !listed.has(id))).toEqual([]);
    });
});
