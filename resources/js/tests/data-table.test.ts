import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { preferences: {} }, url: '/receipts' }),
    router: { on: () => () => undefined, visit: vi.fn() },
    Link: defineComponent({ props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) }),
}));
vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })));

const { default: DataTable } = await import('@/components/table/DataTable.vue');
const { statusBar } = await import('@/lib/statusbar');
const { usePreferences } = await import('@/lib/preferences');

interface Receipt {
    id: string;
    number: string;
    date: string;
    amount: string;
    status: string;
}

const rows: Receipt[] = [
    { id: 'a', number: 'RCT-1', date: '2026-09-02', amount: '45,000.00', status: 'allocated' },
    { id: 'b', number: 'RCT-2', date: '2026-09-12', amount: '1,000.50', status: 'unallocated' },
    { id: 'c', number: 'RCT-3', date: '2026-08-21', amount: '-200.00', status: 'bounced' },
];
const columns = [
    { id: 'number', header: 'Number', value: (r: Receipt) => r.number, href: (r: Receipt) => `/receipts/${r.id}` },
    { id: 'date', header: 'Date', type: 'date' as const, value: (r: Receipt) => r.date },
    { id: 'amount', header: 'Amount', type: 'money' as const, value: (r: Receipt) => r.amount, total: true },
    { id: 'status', header: 'Status', type: 'status' as const, value: (r: Receipt) => r.status, filterOptions: ['allocated', 'unallocated', 'bounced'] },
];

function mountTable(props: Record<string, unknown> = {}) {
    return mount(DataTable as never, {
        props: { id: 'receipts', label: 'Receipts', columns, rows, rowKey: (r: Receipt) => r.id, currency: 'BDT', selectable: true, ...props } as never,
        attachTo: document.body,
    });
}

const bodyRows = (wrapper: ReturnType<typeof mountTable>) => wrapper.findAll('tbody tr[data-row-index]');

beforeEach(() => {
    window.history.replaceState(null, '', '/receipts');
    const preferences = usePreferences();
    preferences.tables = {};
    preferences.views = {};
});

describe('DataTable', () => {
    it('shows the currency once, dates as 12 Sep 2026, negatives in parentheses and a footer total', () => {
        const wrapper = mountTable();
        expect(wrapper.find('thead').text()).toContain('Amount (BDT)');
        expect(bodyRows(wrapper)[1]?.text()).toContain('12 Sep 2026');
        expect(bodyRows(wrapper)[2]?.text()).toContain('(200.00)');
        expect(wrapper.find('tfoot').text()).toContain('45,800.50');
        expect(wrapper.text()).not.toContain('BDT 45');
    });

    it('sorts by a header, adds a second sort with Shift and keeps the sort in the URL', async () => {
        const wrapper = mountTable();
        const amountHeader = wrapper.findAll('thead th button').find((b) => b.text().startsWith('Amount'))!;
        await amountHeader.trigger('click');
        expect(bodyRows(wrapper).map((r) => r.text().slice(0, 5))).toEqual(['RCT-3', 'RCT-2', 'RCT-1']);
        const dateHeader = wrapper.findAll('thead th button').find((b) => b.text().startsWith('Date'))!;
        await dateHeader.trigger('click', { shiftKey: true });
        await nextTick();
        expect(window.location.search).toContain('sort=amount%2Cdate');
    });

    it('filters inline with money expressions, updating totals and the URL', async () => {
        const wrapper = mountTable();
        await wrapper.findAll('[role=toolbar] button').find((b) => b.text().startsWith('Filter'))!.trigger('click');
        const amountFilter = wrapper.find('input[aria-label="Filter Amount"]');
        await amountFilter.setValue('>1000');
        expect(bodyRows(wrapper)).toHaveLength(2);
        expect(wrapper.find('tfoot').text()).toContain('46,000.50');
        expect(decodeURIComponent(window.location.search)).toContain('f.amount=>1000');
    });

    it('moves with the arrow keys, opens with Enter, selects with Space and ranges with Shift, summing the selection in the status bar', async () => {
        const wrapper = mountTable();
        const grid = wrapper.find('[role=grid]');
        await grid.trigger('keydown', { key: 'ArrowDown' });
        await grid.trigger('keydown', { key: 'ArrowDown' });
        await grid.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('open')?.[0]?.[0]).toMatchObject({ id: 'b' });

        await grid.trigger('keydown', { key: 'ArrowUp' });
        await grid.trigger('keydown', { key: ' ' });
        await grid.trigger('keydown', { key: 'ArrowDown', shiftKey: true });
        await flushPromises();
        expect(statusBar.selected).toBe(2);
        expect(statusBar.selectedSum).toBe('46,000.50');
        expect(wrapper.find('[aria-label="Selected rows"]').text()).toContain('2 selected');

        await grid.trigger('keydown', { key: 'Escape' });
        await grid.trigger('keydown', { key: 'Escape' });
        expect(statusBar.selected).toBe(0);
    });

    it('virtualises above 200 rows', () => {
        const many = Array.from({ length: 1000 }, (_, i) => ({ id: `r${i}`, number: `RCT-${i}`, date: '2026-09-01', amount: '1.00', status: 'allocated' }));
        const wrapper = mountTable({ rows: many });
        expect(bodyRows(wrapper).length).toBeLessThan(200);
        expect(statusBar.rows).toBe(1000);
        expect(wrapper.find('tfoot').text()).toContain('1,000.00');
    });

    it('shows one sentence and one action when empty, skeleton rows while loading, and a way out when filters hide everything', async () => {
        const empty = mountTable({ rows: [], emptyText: 'No receipts yet.', emptyAction: { label: 'Record a receipt', href: '/receipts/create' } });
        expect(empty.find('tbody').text()).toContain('No receipts yet.');
        expect(empty.find('tbody a[href="/receipts/create"]').text()).toBe('Record a receipt');

        const loading = mountTable({ loading: true });
        expect(loading.findAll('tbody tr[aria-hidden="true"]').length).toBeGreaterThanOrEqual(8);
        expect(bodyRows(loading)).toHaveLength(0);

        const filtered = mountTable({ emptyAction: { label: 'Record a receipt', href: '/receipts/create' } });
        await filtered.findAll('[role=toolbar] button').find((b) => b.text().startsWith('Filter'))!.trigger('click');
        await filtered.find('input[aria-label="Filter Amount"]').setValue('>999999');
        expect(filtered.find('tbody').text()).toContain('No rows match these filters.');
        expect(filtered.find('tbody a').exists()).toBe(false);
        expect(filtered.find('tbody button').text()).toBe('Clear filters');
    });
});
