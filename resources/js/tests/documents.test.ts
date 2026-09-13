import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import DocumentList from '@/components/object/DocumentList.vue';
import { formatFileSize } from '@/lib/format';

vi.mock('@inertiajs/vue3', () => ({
    useForm: <T extends object>(data: T) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
}));

const survey = { id: 'd1', name: 'survey-report.pdf', mime: 'application/pdf', size_bytes: 254_000, description: 'Surveyor visit', uploaded_by: 'Rafiq Islam', uploaded_at: '2026-09-06T10:15:00+00:00', url: '/claims/c1/documents/d1' };

describe('documents tab (fix F2)', () => {
    it('lists each document with its size, uploader, date and a download link', () => {
        const wrapper = mount(DocumentList, { props: { documents: [survey], uploadUrl: null } });
        const cells = wrapper.findAll('tbody td').map((cell) => cell.text());
        expect(cells[0]).toContain('survey-report.pdf');
        expect(cells[0]).toContain('Surveyor visit');
        expect(cells.slice(1, 4)).toEqual(['248 KB', 'Rafiq Islam', '6 Sep 2026']);
        expect(wrapper.get('tbody a').attributes('href')).toBe('/claims/c1/documents/d1');
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('says one sentence with one action when empty, and offers the action only to people who may attach', () => {
        const allowed = mount(DocumentList, { props: { documents: [], uploadUrl: '/claims/c1/documents' } });
        expect(allowed.get('p').text()).toBe('No documents yet. Attach a document');
        expect(allowed.findAll('p button')).toHaveLength(1);
        expect(allowed.find('input[type="file"]').attributes('accept')).toContain('.pdf');

        const readOnly = mount(DocumentList, { props: { documents: [], uploadUrl: null } });
        expect(readOnly.get('p').text()).toBe('No documents yet.');
        expect(readOnly.find('button').exists()).toBe(false);
    });

    it('offers the printed documents the user may generate, in the chosen language, and lists every version (slice R8)', async () => {
        const generation = {
            url: '/policies/p1/generated-documents',
            actions: [{ label: 'Generate schedule', template_code: 'policy_schedule', object_id: null }, { label: 'Generate endorsement 1 (2026-10-01)', template_code: 'endorsement', object_id: 't1' }],
            locales: [{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }],
            history: [{ id: 'g2', title: 'Policy schedule', number: 'POL-HO-2026-000001', version: 2, locale: 'bn', template_version: 1, rendered_by: 'Rafiq Islam',
                rendered_at: '2026-09-14T10:15:00+00:00', reference: 'a1b2c3d4e5f6', sha256: 'f'.repeat(64), size_bytes: 56_000, url: '/policies/p1/documents/d2' }],
        };
        const wrapper = mount(DocumentList, { props: { documents: [], uploadUrl: null, generation } });
        const buttons = wrapper.findAll('section button');
        expect(buttons.map((b) => b.text())).toEqual(['Generate schedule', 'Generate endorsement 1 (2026-10-01)']);
        const row = wrapper.findAll('section tbody td').map((cell) => cell.text());
        expect(row[0]).toContain('Policy schedule POL-HO-2026-000001');
        expect(row.slice(1, 6)).toEqual(['2', 'বাংলা', 'Rafiq Islam', '14 Sep 2026', 'a1b2c3d4e5f6']);
        expect(wrapper.get('section tbody a').attributes('href')).toBe('/policies/p1/documents/d2');

        await wrapper.get('select').setValue('bn');
        await buttons[1]!.trigger('click');
        const form = (wrapper.findComponent({ name: 'GeneratedDocuments' }).vm as unknown as { $: { setupState: { form: { post: ReturnType<typeof vi.fn>; locale: string; object_id: string | null } } } }).$.setupState.form;
        expect(form.post).toHaveBeenCalledWith('/policies/p1/generated-documents', expect.objectContaining({ preserveScroll: true }));
        expect([form.locale, form.object_id]).toEqual(['bn', 't1']);

        const empty = mount(DocumentList, { props: { documents: [], uploadUrl: null, generation: { ...generation, actions: [], history: [] } } });
        expect(empty.get('section p').text()).toBe('Nothing printed yet.');
        expect(empty.find('section button').exists()).toBe(false);
    });

    it('writes file sizes for people', () => {
        expect(formatFileSize(512)).toBe('512 B');
        expect(formatFileSize(12_700)).toBe('12.4 KB');
        expect(formatFileSize(10 * 1024 * 1024)).toBe('10 MB');
        expect(formatFileSize(254_000)).toBe('248 KB');
    });
});
