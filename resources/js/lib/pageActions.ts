import { getCurrentInstance, onBeforeUnmount, shallowRef, watch } from 'vue';
import { fuzzyScore } from '@/lib/fuzzy';

/**
 * Gap audit GA-29: actions an object page offers the command palette ("Endorse POL-HO-2026-000012", "Cancel …", "Renew …", "Request the refund",
 * "Issue cover note"). A page registers only the actions the server allowed (its `actions` / `can` flags), so the palette never offers what the
 * user may not do. Running one opens the page's own drawer or link.
 */
export interface PageAction {
    id: string;
    label: string;
    keywords?: string;
    href?: string;
    run?: () => void;
}

export interface PageActionSet {
    /** "This policy", "This proposal": the palette group. */
    group: string;
    actions: PageAction[];
}

export const pageActions = shallowRef<PageActionSet | null>(null);

/** Registers the page's actions while it is shown; `source` is re-read when what it depends on changes (a policy cancelled on the page). */
export function usePageActions(source: () => PageActionSet): void {
    const stop = watch(source, (set) => (pageActions.value = set), { immediate: true, deep: true });
    if (getCurrentInstance()) {
        onBeforeUnmount(() => {
            stop();
            pageActions.value = null;
        });
    }
}

/** The page's actions matching what is typed, best first; all of them, in page order, when nothing is typed. */
export function rankPageActions(actions: PageAction[], query: string): PageAction[] {
    const typed = query.trim();
    if (typed === '') return actions;
    return actions
        .map((action, index) => ({ action, index, score: Math.max(fuzzyScore(typed, action.label), fuzzyScore(typed, action.keywords ?? '') * 0.5) }))
        .filter((entry) => entry.score > 0)
        .sort((a, b) => b.score - a.score || a.index - b.index)
        .map((entry) => entry.action);
}

export interface PolicyActionFlags {
    record_receipt: boolean;
    endorse: boolean;
    endorse_risk: boolean;
    cancel: boolean;
    renew: boolean;
    refund?: boolean;
    lapse?: boolean;
    reinstate?: boolean;
    issue?: boolean;
}

/** The policy page's palette actions: only the ones its flags allow. */
export function policyPageActions(number: string, policyId: string, flags: PolicyActionFlags, open: { endorse: () => void; endorseRisk: () => void; cancel: () => void; renew: () => void; issue: () => void }): PageAction[] {
    const actions: PageAction[] = [];
    if (flags.issue) actions.push({ id: 'policy-issue', label: `Issue ${number}`, keywords: 'issue policy', run: open.issue });
    if (flags.record_receipt) actions.push({ id: 'policy-receipt', label: `Record a receipt for ${number}`, keywords: 'receipt payment collect premium', href: `/receipts/create?policy=${policyId}` });
    if (flags.endorse) actions.push({ id: 'policy-endorse', label: `Endorse ${number}`, keywords: 'endorsement change premium', run: open.endorse });
    if (flags.endorse_risk) actions.push({ id: 'policy-endorse', label: `Endorse ${number}`, keywords: 'endorsement change risk re-rate', run: open.endorseRisk });
    if (flags.renew) actions.push({ id: 'policy-renew', label: `Renew ${number}`, keywords: 'renewal quote next term', run: open.renew });
    if (flags.refund) actions.push({ id: 'policy-refund', label: `Request the refund on ${number}`, keywords: 'refund money back customer', href: `/refunds?policy=${policyId}` });
    if (flags.cancel) actions.push({ id: 'policy-cancel', label: `Cancel ${number}`, keywords: 'cancellation cancel policy', run: open.cancel });
    return actions;
}

export interface ProposalActionFlags {
    submit: boolean;
    issue_cover_note: boolean;
    issue_policy: boolean;
    decide: boolean;
}

/** The proposal page's palette actions: submit, cover note, issue the policy, open referrals — only when allowed. */
export function proposalPageActions(number: string, flags: ProposalActionFlags, open: { submit: () => void; coverNote: () => void; issuePolicy: () => void }): PageAction[] {
    const actions: PageAction[] = [];
    if (flags.submit) actions.push({ id: 'proposal-submit', label: `Submit ${number} to underwriting`, keywords: 'submit underwriting', run: open.submit });
    if (flags.issue_cover_note) actions.push({ id: 'proposal-cover-note', label: `Issue a cover note for ${number}`, keywords: 'cover note temporary evidence', run: open.coverNote });
    if (flags.issue_policy) actions.push({ id: 'proposal-issue', label: `Issue the policy for ${number}`, keywords: 'issue policy', run: open.issuePolicy });
    if (flags.decide) actions.push({ id: 'proposal-referrals', label: 'Open referrals', keywords: 'underwriting decide approve decline', href: '/underwriting/referrals' });
    return actions;
}
