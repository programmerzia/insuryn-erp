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

    it('writes file sizes for people', () => {
        expect(formatFileSize(512)).toBe('512 B');
        expect(formatFileSize(12_700)).toBe('12.4 KB');
        expect(formatFileSize(10 * 1024 * 1024)).toBe('10 MB');
        expect(formatFileSize(254_000)).toBe('248 KB');
    });
});
