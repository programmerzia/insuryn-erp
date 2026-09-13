import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const listeners: Record<string, (event: { detail: { visit: Record<string, unknown> }; preventDefault: () => void }) => void> = {};
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { on: (name: string, fn: never) => ((listeners[name] = fn), () => delete listeners[name]), visit },
    usePage: () => ({ props: {} }),
}));

const { useUnsavedGuard } = await import('@/lib/unsaved');
const { confirmState, settleConfirm } = await import('@/lib/confirm');

describe('unsaved-changes guard', () => {
    it('stops navigation away from a dirty form until the user agrees, and never blocks the form submit', async () => {
        let dirty = true;
        mount(defineComponent({ setup: () => (useUnsavedGuard(() => dirty), () => h('div')) }));
        const prevent = vi.fn();

        listeners.before?.({ detail: { visit: { url: new URL('http://x/receipts'), method: 'post' } }, preventDefault: prevent });
        expect(prevent).not.toHaveBeenCalled();

        listeners.before?.({ detail: { visit: { url: new URL('http://x/policies'), method: 'get' } }, preventDefault: prevent });
        expect(prevent).toHaveBeenCalledOnce();
        expect(confirmState.request?.confirmLabel).toBe('Leave and discard');
        settleConfirm(true);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(visit).toHaveBeenCalledWith(new URL('http://x/policies'), expect.objectContaining({ method: 'get' }));

        dirty = false;
        const cleanPrevent = vi.fn();
        listeners.before?.({ detail: { visit: { url: new URL('http://x/claims'), method: 'get' } }, preventDefault: cleanPrevent });
        expect(cleanPrevent).not.toHaveBeenCalled();
    });
});
