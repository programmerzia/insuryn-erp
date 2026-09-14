import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import ConfirmHost from '@/components/shell/ConfirmHost.vue';
import { confirmAction } from '@/lib/confirm';

/** The shell's confirmation dialog answers what the user clicked (the flow audit found confirming answered "no", so "make proposal" did nothing). */
describe('confirmation dialog', () => {
    async function ask(): Promise<{ answer: Promise<boolean>; button: (label: string) => HTMLButtonElement }> {
        mount(ConfirmHost, { attachTo: document.body });
        const answer = confirmAction({ title: 'Make a proposal from QUO-HO-2026-000001?', body: 'The customer accepts this quotation.', confirmLabel: 'Make proposal' });
        await nextTick();
        await nextTick();
        const button = (label: string) => [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === label) as HTMLButtonElement;
        return { answer, button };
    }

    it('resolves yes when the confirm button is clicked', async () => {
        const { answer, button } = await ask();
        expect(button('Make proposal')).toBeTruthy();
        button('Make proposal').click();
        await expect(answer).resolves.toBe(true);
    });

    it('resolves no when cancelled', async () => {
        const { answer, button } = await ask();
        button('Cancel').click();
        await expect(answer).resolves.toBe(false);
    });
});
