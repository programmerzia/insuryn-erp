import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import { pageActions, policyPageActions, proposalPageActions, rankPageActions, usePageActions } from '@/lib/pageActions';
import { pageUrl, partialNotice } from '@/lib/paging';
import { navOpen, PHONE_QUERY, toggleNavigation } from '@/lib/phone';

/** Gap audit GA-29: object pages hand the command palette the actions they allow — and only those. GA-26/GA-40: server pages keep the list's filters. */
const noop = { endorse: () => undefined, endorseRisk: () => undefined, cancel: () => undefined, renew: () => undefined, issue: () => undefined };
const none = { issue: false, record_receipt: false, endorse: false, endorse_risk: false, cancel: false, renew: false, refund: false };

describe('policy page actions', () => {
    it('offers endorse, cancel, renew, refund and receipt only when the page allows them', () => {
        expect(policyPageActions('POL-HO-2026-000012', 'p1', none, noop)).toEqual([]);
        const all = policyPageActions('POL-HO-2026-000012', 'p1', { ...none, record_receipt: true, endorse: true, cancel: true, renew: true, refund: true }, noop);
        expect(all.map((a) => a.label)).toEqual(['Record a receipt for POL-HO-2026-000012', 'Endorse POL-HO-2026-000012', 'Renew POL-HO-2026-000012',
            'Request the refund on POL-HO-2026-000012', 'Cancel POL-HO-2026-000012']);
        expect(all.find((a) => a.id === 'policy-refund')?.href).toBe('/refunds?policy=p1');
        expect(all.find((a) => a.id === 'policy-receipt')?.href).toBe('/receipts/create?policy=p1');
    });

    it('opens the page\'s own drawers', () => {
        const opened: string[] = [];
        const actions = policyPageActions('POL-1', 'p1', { ...none, endorse_risk: true, cancel: true }, { ...noop, endorseRisk: () => opened.push('risk'), cancel: () => opened.push('cancel') });
        actions.forEach((a) => a.run?.());
        expect(opened).toEqual(['risk', 'cancel']);
    });

    it('offers the cover note and policy issue on a proposal only when allowed', () => {
        const open = { submit: () => undefined, coverNote: () => undefined, issuePolicy: () => undefined };
        expect(proposalPageActions('PRP-HO-1', { submit: false, issue_cover_note: false, issue_policy: false, decide: false }, open)).toEqual([]);
        expect(proposalPageActions('PRP-HO-1', { submit: false, issue_cover_note: true, issue_policy: true, decide: false }, open).map((a) => a.label))
            .toEqual(['Issue a cover note for PRP-HO-1', 'Issue the policy for PRP-HO-1']);
    });

    it('ranks the page\'s actions by what is typed', () => {
        const actions = policyPageActions('POL-1', 'p1', { ...none, endorse: true, cancel: true, refund: true }, noop);
        expect(rankPageActions(actions, '').map((a) => a.id)).toEqual(['policy-endorse', 'policy-refund', 'policy-cancel']);
        expect(rankPageActions(actions, 'cancel')[0]?.id).toBe('policy-cancel');
        expect(rankPageActions(actions, 'refund').map((a) => a.id)).toEqual(['policy-refund']);
    });

    it('registers the actions while the page is shown and forgets them when it is left', async () => {
        const allowed = ref(true);
        const Page = defineComponent({
            setup() {
                usePageActions(() => ({ group: 'This policy', actions: policyPageActions('POL-1', 'p1', { ...none, cancel: allowed.value }, noop) }));
                return () => h('div');
            },
        });
        const wrapper = mount(Page);
        expect(pageActions.value?.actions.map((a) => a.id)).toEqual(['policy-cancel']);
        allowed.value = false; // the policy was cancelled on the page
        await nextTick();
        expect(pageActions.value?.actions).toEqual([]);
        wrapper.unmount();
        expect(pageActions.value).toBeNull();
    });
});

describe('lists that hold part of the rows (GA-40)', () => {
    it('says how many of how many rows are shown, and where the pager is', () => {
        expect(partialNotice(5000, { current: 1, last: 3, total: 12431 })).toBe('Showing 5,000 of 12,431 · page 1 of 3; the pager is in the status bar. Filters and totals cover this page.');
        expect(partialNotice(100, null, 240)).toBe('Showing the first 100 of 240.');
        expect(partialNotice(12, { current: 1, last: 1, total: 12 })).toBeNull();
        expect(partialNotice(12, null, 12)).toBeNull();
        expect(partialNotice(12)).toBeNull();
    });
});

describe('phone navigation (GA-16)', () => {
    it('opens the sidebar over the page on a phone and collapses it on a desktop', () => {
        const original = window.matchMedia;
        let phoneWidth = true;
        window.matchMedia = ((query: string) => ({ matches: phoneWidth && query === PHONE_QUERY, media: query, addEventListener: () => undefined, removeEventListener: () => undefined })) as never;
        let collapsed = 0;
        navOpen.value = false;
        toggleNavigation(() => collapsed++);
        expect(navOpen.value).toBe(true);
        toggleNavigation(() => collapsed++);
        expect(navOpen.value).toBe(false);
        expect(collapsed).toBe(0);
        phoneWidth = false;
        toggleNavigation(() => collapsed++);
        expect(collapsed).toBe(1);
        expect(navOpen.value).toBe(false);
        window.matchMedia = original;
    });
});

describe('server pages', () => {
    it('keeps the filters and sort of the list when moving between pages', () => {
        expect(pageUrl('/claims?f.status=registered&sort=-reported_on', 2)).toBe('/claims?f.status=registered&sort=-reported_on&page=2');
        expect(pageUrl('/claims?f.status=registered&page=3', 1)).toBe('/claims?f.status=registered');
        expect(pageUrl('/policies', 4)).toBe('/policies?page=4');
    });
});
