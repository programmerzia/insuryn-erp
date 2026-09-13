<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import DetailList from '@/components/table/DetailList.vue';
import QueueView from '@/components/table/QueueView.vue';
import type { DataColumn } from '@/components/table/types';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';

/**
 * Slice R8 (design §3): the document templates — every version by document, product class and language, with its status. One version of each is
 * in use; editing it makes a new draft, activating the draft retires the old version. A class template is used for that class instead of the
 * template for every class.
 */
interface TemplateRow {
    id: string; code: string; title: string; product_class: string | null; locale: string; version: number; status: string; updated_at: string;
    created_by: string; activated_at: string | null; activated_by: string | null;
}
const props = defineProps<{ templates: TemplateRow[]; codes: { value: string; label: string }[]; classes: { code: string; name: string }[] }>();

const active = ref<string | null>(null);
const open = ref(false);
const languages = [{ value: 'en', label: 'English' }, { value: 'bn', label: 'Bangla' }];
const language = (code: string) => languages.find((l) => l.value === code)?.label ?? code;
const className = (code: string | null) => (code === null ? 'Every class' : (props.classes.find((c) => c.code === code)?.name ?? code));
const form = useForm({ code: props.codes[0]?.value ?? '', product_class: '', locale: 'en' });
const errors = computed(() => form.errors as Record<string, string>);
const classOptions = computed(() => props.classes.map((c) => ({ value: c.code, label: c.name })));

function openNew(): void {
    form.reset();
    form.clearErrors();
    open.value = true;
}

const columns: DataColumn<TemplateRow>[] = [
    { id: 'title', header: 'Document', value: (t) => t.title, href: (t) => `/documents/templates/${t.id}`, width: 200, filterOptions: props.codes.map((c) => c.label) },
    { id: 'class', header: 'Product class', value: (t) => className(t.product_class), width: 150 },
    { id: 'locale', header: 'Language', value: (t) => language(t.locale), width: 110, filterOptions: languages.map((l) => l.label) },
    { id: 'version', header: 'Version', value: (t) => String(t.version), width: 90 },
    { id: 'status', header: 'Status', type: 'status', value: (t) => t.status, filterOptions: ['draft', 'active', 'retired'] },
    { id: 'updated', header: 'Changed', type: 'date', value: (t) => t.updated_at, width: 120 },
];
</script>

<template>
    <AppLayout title="Document templates" fill>
        <QueueView
            id="document-templates"
            v-model:active="active"
            title="Document templates"
            :columns="columns"
            :rows="templates"
            :row-key="(t) => t.id"
            empty-text="No document templates yet, so nothing can be printed."
            :action="{ label: 'New template' }"
            :inspector-title="(t) => `${t.title} · ${language(t.locale)}`"
            :inspector-subtitle="(t) => `${className(t.product_class)} · version ${t.version}`"
            @action="openNew"
        >
            <template #details="{ row }">
                <DetailList
                    :items="[
                        { label: 'Status', value: row.status },
                        { label: 'Created by', value: row.created_by },
                        { label: 'In use since', value: row.activated_at ? `${formatDate(row.activated_at)} (${row.activated_by ?? 'someone who has left'})` : null },
                        { label: 'Changed', value: formatDate(row.updated_at) },
                    ]"
                />
                <Link :href="`/documents/templates/${row.id}`" class="mt-4 inline-block text-ui text-accent-text hover:underline">{{ row.status === 'draft' ? 'Edit the draft' : 'Open and preview' }}</Link>
            </template>
        </QueueView>

        <Drawer v-model:open="open" title="New template">
            <p class="mb-4 text-ui text-ink-2">The draft starts from the template in use for every class. Choose a product class to print that class differently.</p>
            <FormLayout submit-label="Create draft" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="form.post('/documents/templates')" @cancel="open = false">
                <Field id="code" label="Document" :error="errors.code"><SelectInput id="code" v-model="form.code" :options="codes" /></Field>
                <Field id="product_class" label="Product class" optional :error="errors.product_class">
                    <SelectInput id="product_class" v-model="form.product_class" placeholder="Every class" :options="classOptions" />
                </Field>
                <Field id="locale" label="Language" :error="errors.locale"><SelectInput id="locale" v-model="form.locale" :options="languages" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
