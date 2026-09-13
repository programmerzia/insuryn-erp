import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import DemoAccountsDialog, { type DemoAccount } from '@/components/auth/DemoAccountsDialog.vue';

const accounts: DemoAccount[] = [
    { email: 'accountant@demo.local', name: 'Accountant', roles: ['Accountant'], password: 'Seed-pass-1' },
    { email: 'admin@demo.local', name: 'Tenant Admin', roles: ['Finance Manager', 'Tenant Admin'], password: 'Seed-pass-1' },
];

describe('demo accounts dialog', () => {
    it('lists each account with its roles and hands back the chosen one, then closes', async () => {
        const wrapper = mount(DemoAccountsDialog, { props: { accounts, open: true, 'onUpdate:open': (v: boolean) => wrapper.setProps({ open: v }) }, attachTo: document.body });
        await nextTick();

        expect(document.body.textContent).toContain('Finance Manager, Tenant Admin');
        (document.querySelector('[aria-label="Use admin@demo.local"]') as HTMLButtonElement).click();
        await nextTick();

        expect(wrapper.emitted('choose')).toEqual([[accounts[1]]]);
        expect(wrapper.props('open')).toBe(false);
        wrapper.unmount();
    });

    it('says how to create the accounts when none are seeded', async () => {
        const wrapper = mount(DemoAccountsDialog, { props: { accounts: [], open: true }, attachTo: document.body });
        await nextTick();

        expect(document.body.textContent).toContain('Run composer db:fresh to create them.');
        wrapper.unmount();
    });
});
