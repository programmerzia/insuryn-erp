<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';
import { HttpError, requestJson } from '@/lib/http';

/**
 * Slice R8 template editor (design §3 "tenant-editable body, preview with demo data, variables documented"): the Blade body and letterhead on the
 * left, the page they print with demo data on the right (a sandboxed frame, refreshed as you type), the variables of the document below. Saving a
 * version in use makes a new draft; activating a draft puts it in use and retires the previous version.
 */
interface TemplateRow { id: string; code: string; title: string; product_class: string | null; locale: string; version: number; status: string; updated_at: string; created_by: string; activated_at: string | null; activated_by: string | null }
const props = defineProps<{ template: TemplateRow & { body: string; letterhead: string }; versions: TemplateRow[]; variables: { name: string; description: string }[] }>();

const form = useForm({ body: props.template.body, letterhead: props.template.letterhead });
const errors = computed(() => form.errors as Record<string, string>);
const preview = ref<string>('');
const previewError = ref<string | null>(null);
const refreshing = ref(false);
const language = computed(() => (props.template.locale === 'bn' ? 'Bangla' : 'English'));
const heading = computed(() => `${props.template.title} · ${language.value} · ${props.template.product_class ?? 'every class'}`);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

async function refresh(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    refreshing.value = true;
    try {
        const result = await requestJson<{ html: string | null; message: string | null }>('POST', '/documents/templates/preview',
            { code: props.template.code, locale: props.template.locale, body: form.body, letterhead: form.letterhead }, controller.signal);
        preview.value = result.html ?? '';
        previewError.value = null;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        const body = error instanceof HttpError ? (error.body as { message?: string } | null) : null;
        previewError.value = body?.message ?? 'The preview could not be loaded. Check your connection and keep editing.';
    } finally {
        refreshing.value = false;
    }
}

watch(() => [form.body, form.letterhead], () => {
    clearTimeout(timer);
    timer = setTimeout(() => void refresh(), 500);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});
void refresh();

function save(): void {
    form.put(`/documents/templates/${props.template.id}`, { preserveScroll: true });
}

async function activate(): Promise<void> {
    const current = props.versions.find((v) => v.status === 'active');
    if (await confirmAction({ title: `Use version ${props.template.version}?`, body: current ? `Version ${current.version} is retired and new documents print with this version. Documents already generated keep the version they were printed with.` : 'New documents print with this version.', confirmLabel: 'Activate' })) {
        router.post(`/documents/templates/${props.template.id}/activate`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout :title="`${template.title} template`">
        <div class="px-6 py-4">
            <Breadcrumb :base="[{ label: 'Document templates', href: '/documents/templates' }]" />
            <div class="mt-1 mb-4 flex flex-wrap items-center gap-3">
                <h1 class="text-title font-semibold">{{ heading }}</h1>
                <span class="text-ui text-ink-2">Version {{ template.version }}</span>
                <StatusBadge :status="template.status" />
                <span class="flex-1" />
                <a :href="`/documents/templates/${template.id}/preview?format=pdf`" target="_blank" rel="noopener" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Preview saved version as PDF</a>
                <button v-if="template.status === 'draft'" type="button" :disabled="form.isDirty" :title="form.isDirty ? 'Save the draft first' : undefined" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50" @click="activate">Activate</button>
            </div>
            <p v-if="template.status !== 'draft'" class="mb-4 max-w-[880px] text-ui text-ink-2">
                This version is {{ template.status === 'active' ? 'in use' : 'retired' }} and is never changed. Saving makes a new draft version from your edits.
            </p>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <FormLayout wide submit-label="Save draft" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="save">
                    <Field id="letterhead" label="Letterhead" optional hint="Leave empty to print the company name. Images must be embedded as data: URIs." :error="errors.letterhead">
                        <textarea id="letterhead" v-model="form.letterhead" rows="4" spellcheck="false" class="w-full rounded-control border border-line-control bg-surface px-2 py-1.5 text-dense text-ink" />
                    </Field>
                    <Field id="body" label="Body" hint="HTML with {{ $variable }}, @if, @foreach. Values are always escaped; PHP and other Blade directives are refused." :error="errors.body">
                        <textarea id="body" v-model="form.body" rows="28" spellcheck="false" class="w-full rounded-control border border-line-control bg-surface px-2 py-1.5 text-dense text-ink" />
                    </Field>
                </FormLayout>

                <section class="grid content-start gap-2" aria-labelledby="preview-title">
                    <div class="flex items-center gap-2">
                        <h2 id="preview-title" class="text-ui font-medium">Preview with demo data</h2>
                        <span v-if="refreshing" class="text-dense text-ink-2">Updating…</span>
                    </div>
                    <p v-if="previewError" class="border-l-2 border-danger pl-3 text-ui text-danger" role="alert">{{ previewError }}</p>
                    <iframe title="Document preview" sandbox="" :srcdoc="preview" class="h-[760px] w-full rounded-panel border border-line bg-surface" />
                </section>
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-2">
                <section aria-labelledby="variables-title">
                    <h2 id="variables-title" class="mb-2 text-ui font-medium">Variables</h2>
                    <div class="overflow-x-auto border border-line">
                        <table class="w-full border-separate border-spacing-0 text-dense">
                            <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="w-44 border-b border-line px-3 text-left font-medium">Name</th><th class="border-b border-line px-3 text-left font-medium">Holds</th></tr></thead>
                            <tbody>
                                <tr v-for="variable in variables" :key="variable.name" class="h-(--row-h) align-top">
                                    <td class="border-b border-line px-3 py-1.5">${{ variable.name }}</td>
                                    <td class="border-b border-line px-3 py-1.5">{{ variable.description }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section aria-labelledby="versions-title">
                    <h2 id="versions-title" class="mb-2 text-ui font-medium">Versions</h2>
                    <div class="overflow-x-auto border border-line">
                        <table class="w-full border-separate border-spacing-0 text-dense">
                            <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)"><th class="w-20 border-b border-line px-3 text-right font-medium">Version</th><th class="border-b border-line px-3 text-left font-medium">Status</th><th class="border-b border-line px-3 text-left font-medium">Created by</th><th class="border-b border-line px-3 text-left font-medium">Changed</th></tr></thead>
                            <tbody>
                                <tr v-for="version in versions" :key="version.id" class="h-(--row-h)" :class="version.id === template.id ? 'bg-accent-soft' : ''">
                                    <td class="border-b border-line px-3 text-right tabular-nums"><Link :href="`/documents/templates/${version.id}`" class="text-accent-text hover:underline">{{ version.version }}</Link></td>
                                    <td class="border-b border-line px-3"><StatusBadge :status="version.status" /></td>
                                    <td class="truncate border-b border-line px-3">{{ version.created_by }}</td>
                                    <td class="border-b border-line px-3">{{ formatDate(version.updated_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
