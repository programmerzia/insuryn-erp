import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const put = vi.fn(() => Promise.resolve(undefined));
const loadHelp = vi.fn((module: string, locale: string) => Promise.resolve({ module, locale, title: `${module} ${locale}`, html: '<p>Text.</p>' }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { preferences: { locale: 'en' } } }), router: { on: () => () => undefined } }));
vi.mock('@/lib/http', () => ({ requestJson: (...args: unknown[]) => put(...(args as [])) }));
vi.mock('@/lib/help', () => ({ loadHelp: (module: string, locale: string) => loadHelp(module, locale) }));

describe('help panel language (GA-30)', () => {
    it('switches only the panel to Bangla, keeping the user\'s language, and follows the user\'s language again', async () => {
        const { default: HelpPanel } = await import('@/components/shell/HelpPanel.vue');
        const { usePreferences, savePreference } = await import('@/lib/preferences');
        const preferences = usePreferences();
        const wrapper = mount(HelpPanel, { props: { module: 'claims' } });
        await flushPromises();
        expect(loadHelp).toHaveBeenLastCalledWith('claims', 'en');

        await wrapper.get('button[lang="bn"]').trigger('click');
        await flushPromises();
        expect(preferences.locale).toBe('en');
        expect(preferences.help_locale).toBe('bn');
        expect(loadHelp).toHaveBeenLastCalledWith('claims', 'bn');

        await wrapper.findAll('button').find((b) => b.text() === 'English')!.trigger('click');
        await flushPromises();
        expect(preferences.help_locale).toBeNull();

        // The user menu's choice changes the whole interface's language; the panel follows it.
        savePreference('locale', 'bn', 0);
        await flushPromises();
        expect(loadHelp).toHaveBeenLastCalledWith('claims', 'bn');
    });
});
