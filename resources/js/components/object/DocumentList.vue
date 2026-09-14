<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import TextInput from '@/components/forms/TextInput.vue';
import GeneratedDocuments from '@/components/object/GeneratedDocuments.vue';
import type { DocumentGeneration, StoredDocumentRow } from '@/components/object/types';
import { formatDate, formatFileSize } from '@/lib/format';

/**
 * The Documents tab (fix F2): the object's documents, newest first, each downloaded with its original name, and — when the user may attach —
 * a file and an optional description. Empty state (brief §4): one sentence and one action.
 */
const props = defineProps<{ documents: StoredDocumentRow[]; uploadUrl?: string | null; generation?: DocumentGeneration | null }>();

/** ASSUMPTION: A-52 — mirrors config erp.documents (the server validates; this only narrows the file chooser). */
const ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx';
const fileInput = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null; description: string }>({ file: null, description: '' });

function choose(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.clearErrors('file');
}

function openChooser(): void {
    fileInput.value?.focus();
    fileInput.value?.click();
}

function attach(): void {
    if (!props.uploadUrl) return;
    form.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}
</script>

<template>
    <div class="grid max-w-[900px] gap-4">
        <GeneratedDocuments v-if="generation" :generation="generation" />
        <h2 v-if="generation" class="text-ui font-medium">All documents</h2>
        <div v-if="documents.length" class="overflow-x-auto border border-line">
            <table class="w-full table-fixed border-separate border-spacing-0 text-dense max-sm:min-w-[36rem]">
                <colgroup><col /><col style="width: 90px" /><col style="width: 170px" /><col style="width: 110px" /><col style="width: 90px" /></colgroup>
                <thead class="bg-surface-2 text-ink-2">
                    <tr class="h-(--row-h)">
                        <th class="border-b border-line px-3 text-left font-medium">Document</th>
                        <th class="border-b border-line px-3 text-right font-medium">Size</th>
                        <th class="border-b border-line px-3 text-left font-medium">Uploaded by</th>
                        <th class="border-b border-line px-3 text-left font-medium">Date</th>
                        <th class="border-b border-line px-3"><span class="sr-only">Download</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="document in documents" :key="document.id" class="h-(--row-h) align-top">
                        <td class="border-b border-line px-3 py-1.5">
                            <p class="truncate" :title="document.name">{{ document.name }}</p>
                            <p v-if="document.description" class="truncate text-ink-2" :title="document.description">{{ document.description }}</p>
                        </td>
                        <td class="border-b border-line px-3 py-1.5 text-right tabular-nums">{{ formatFileSize(document.size_bytes) }}</td>
                        <td class="truncate border-b border-line px-3 py-1.5">{{ document.uploaded_by }}</td>
                        <td class="border-b border-line px-3 py-1.5">{{ formatDate(document.uploaded_at) }}</td>
                        <td class="border-b border-line px-3 py-1.5 text-right"><a :href="document.url" class="text-accent-text hover:underline" :aria-label="`Download ${document.name}`">Download</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="text-ui text-ink-2">
            No documents yet.
            <button v-if="uploadUrl" type="button" class="ml-1 text-accent-text hover:underline" @click="openChooser">Attach a document</button>
        </p>

        <form v-if="uploadUrl" class="grid max-w-[560px] gap-3" @submit.prevent="attach">
            <h2 class="text-ui font-medium">Attach a document</h2>
            <Field id="document-file" label="File" hint="PDF, JPG or PNG image, Word or Excel; up to 10 MB." :error="form.errors.file">
                <input
                    id="document-file"
                    ref="fileInput"
                    type="file"
                    :accept="ACCEPT"
                    :aria-invalid="!!form.errors.file"
                    class="block w-full text-ui text-ink file:mr-3 file:h-8 file:rounded-control file:border file:border-line-control file:bg-surface file:px-3 file:text-ui file:text-ink hover:file:bg-surface-2"
                    @change="choose"
                />
            </Field>
            <Field id="document-description" label="Description" optional :error="form.errors.description">
                <TextInput v-model="form.description" :maxlength="500" placeholder="For example: surveyor's report, 6 Sep" />
            </Field>
            <p v-if="(form.errors as Record<string, string>).form" class="text-dense text-danger" role="alert">{{ (form.errors as Record<string, string>).form }}</p>
            <div>
                <button type="submit" :disabled="!form.file || form.processing" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50">
                    {{ form.processing ? 'Attaching…' : 'Attach' }}
                </button>
            </div>
        </form>
    </div>
</template>
