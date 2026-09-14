import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import LookupInput from '@/components/forms/LookupInput.vue';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { auth: { permissions: [] }, preferences: {} } }) }));

describe('LookupInput', () => {
    it('opens its list on focus while nothing is picked, so recent picks and "New customer" are offered', async () => {
        const wrapper = mount(LookupInput, { props: { type: 'customer', creatable: true, id: 'customer' }, attachTo: document.body });
        await wrapper.get('input').trigger('focus');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('stays closed when focus returns to a field that already holds a pick (after creating a record inline the list must not cover the next field)', async () => {
        const wrapper = mount(LookupInput, {
            props: { type: 'customer', creatable: true, id: 'customer', modelValue: 'p-1', initial: { id: 'p-1', label: 'Shafiq Rahman' } },
            attachTo: document.body,
        });
        await wrapper.get('input').trigger('focus');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);

        await wrapper.get('input').trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);
        wrapper.unmount();
    });
});
