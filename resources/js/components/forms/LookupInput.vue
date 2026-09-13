<script setup lang="ts">
import { Plus, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import Field from '@/components/forms/Field.vue';
import Drawer from '@/components/ui/Drawer.vue';
import Kbd from '@/components/ui/Kbd.vue';
import { useField } from '@/lib/field';
import { HttpError, requestJson } from '@/lib/http';
import { savePreference, usePreferences } from '@/lib/preferences';
import { shortcutKeys } from '@/lib/shortcuts';

/**
 * Brief §4 lookup: type a number or name, pick with ↑↓ Enter; recent picks show first; Ctrl+N creates a customer inline in a drawer.
 * GET /lookup/{type}?q=, POST /lookup/customer. The model is the record id; `selected` carries the picked label for summaries.
 */
export interface LookupResult {
    id: string;
    label: string;
    detail?: string;
    amount?: string;
}

const props = withDefaults(defineProps<{ type: 'customer' | 'agent' | 'policy' | 'installment'; placeholder?: string; initial?: LookupResult | null; creatable?: boolean; id?: string }>(), {
    placeholder: undefined, initial: null, creatable: false, id: undefined,
});
const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ selected: [result: LookupResult | null] }>();
const field = useField(props.id);
const preferences = usePreferences();

const query = ref(props.initial?.label ?? '');
const results = ref<LookupResult[]>([]);
const open = ref(false);
const active = ref(0);
const loading = ref(false);
const failed = ref<string | null>(null);
const recentKey = `lookup-${props.type}`;
const recents = computed(() => ((preferences.drafts[recentKey] as LookupResult[] | undefined) ?? []).slice(0, 5));
const shown = computed(() => (query.value.trim() === '' || query.value === selectedLabel.value ? recents.value : results.value));
const selectedLabel = ref(props.initial?.label ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;

watch(query, (value) => {
    if (value === selectedLabel.value) return;
    active.value = 0;
    open.value = true;
    clearTimeout(timer);
    controller?.abort();
    if (value.trim() === '') {
        results.value = [];
        return;
    }
    loading.value = true;
    timer = setTimeout(async () => {
        controller = new AbortController();
        try {
            results.value = (await requestJson<{ results: LookupResult[] }>('GET', `/lookup/${props.type}?q=${encodeURIComponent(value)}`, undefined, controller.signal)).results;
            failed.value = null;
        } catch (error) {
            if (error instanceof HttpError) failed.value = error.status === 403 ? 'You cannot look these up.' : 'The search did not answer. Try again.';
        } finally {
            loading.value = false;
        }
    }, 150);
});

function pick(result: LookupResult): void {
    model.value = result.id;
    selectedLabel.value = result.label;
    query.value = result.label;
    open.value = false;
    emit('selected', result);
    savePreference(`drafts.${recentKey}`, [result, ...recents.value.filter((r) => r.id !== result.id)].slice(0, 8), 0);
}

function clear(): void {
    if (query.value.trim() === '' && model.value !== '') {
        model.value = '';
        selectedLabel.value = '';
        emit('selected', null);
    }
}

function onBlur(): void {
    setTimeout(() => (open.value = false), 120);
    clear();
}

function onKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'n' && props.creatable) {
        event.preventDefault();
        creating.value = true;
        draft.value.display_name = query.value === selectedLabel.value ? '' : query.value;
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        active.value = Math.min(active.value + 1, shown.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter' && open.value && shown.value[active.value]) {
        event.preventDefault();
        pick(shown.value[active.value]!);
    } else if (event.key === 'Escape' && open.value) {
        event.stopPropagation();
        open.value = false;
    }
}

// Inline create (customers)
const creating = ref(false);
const draft = ref({ display_name: '', kind: 'individual', tax_id: '' });
const createErrors = ref<Record<string, string>>({});
const saving = ref(false);
async function create(): Promise<void> {
    saving.value = true;
    try {
        const response = await requestJson<{ result: LookupResult }>('POST', '/lookup/customer', { ...draft.value, tax_id: draft.value.tax_id || null });
        creating.value = false;
        createErrors.value = {};
        pick(response.result);
    } catch (error) {
        const body = error instanceof HttpError ? (error.body as { errors?: Record<string, string[]>; message?: string }) : null;
        createErrors.value = Object.fromEntries(Object.entries(body?.errors ?? { form: [body?.message ?? 'The customer was not created. Try again.'] }).map(([k, v]) => [k, Array.isArray(v) ? (v[0] ?? '') : String(v)]));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="relative">
        <div class="relative">
            <Search :size="14" :stroke-width="1.5" class="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-ink-2" aria-hidden="true" />
            <input
                v-bind="field"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                :aria-expanded="open"
                :aria-controls="`${field.id}-options`"
                :placeholder="placeholder ?? 'Type a number or name'"
                class="h-8 w-full rounded-control border border-line-control bg-surface pr-2 pl-7 text-body text-ink placeholder:text-ink-2 aria-[invalid=true]:border-danger"
                @focus="open = true"
                @blur="onBlur"
                @keydown="onKeydown"
            />
        </div>
        <ul
            v-if="open && (shown.length || loading || failed || creatable)"
            :id="`${field.id}-options`"
            role="listbox"
            class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-panel border border-line bg-surface p-1 text-ui shadow-float"
        >
            <li v-if="query.trim() === '' && recents.length" class="px-2 pt-1 pb-0.5 text-dense text-ink-2">Recent</li>
            <li
                v-for="(result, index) in shown"
                :key="result.id"
                role="option"
                :aria-selected="index === active"
                class="flex cursor-default flex-col rounded-control px-2 py-1.5"
                :class="index === active ? 'bg-accent-soft' : ''"
                @mousedown.prevent="pick(result)"
                @mousemove="active = index"
            >
                <span class="text-ink">{{ result.label }}</span>
                <span v-if="result.detail" class="truncate text-dense text-ink-2">{{ result.detail }}</span>
            </li>
            <li v-if="loading && !shown.length" class="px-2 py-1.5 text-ink-2" role="status">Searching…</li>
            <li v-else-if="failed" class="px-2 py-1.5 text-danger" role="alert">{{ failed }}</li>
            <li v-else-if="!loading && query.trim() !== '' && query !== selectedLabel && !shown.length" class="px-2 py-1.5 text-ink-2">Nothing matches “{{ query }}”.</li>
            <li v-if="creatable" class="mt-1 border-t border-line pt-1">
                <button type="button" class="flex h-8 w-full items-center gap-2 rounded-control px-2 text-accent-text hover:bg-surface-2" @mousedown.prevent="creating = true; draft.display_name = query === selectedLabel ? '' : query">
                    <Plus :size="14" :stroke-width="1.5" />New customer <Kbd :keys="shortcutKeys('lookup.create')" class="ml-auto" />
                </button>
            </li>
        </ul>

        <Drawer v-if="creatable" v-model:open="creating" title="New customer">
            <form class="grid gap-4" @submit.prevent="create">
                <p v-if="createErrors.form" class="text-ui text-danger" role="alert">{{ createErrors.form }}</p>
                <Field id="new-customer-name" label="Name" :error="createErrors.display_name">
                    <input id="new-customer-name" v-model="draft.display_name" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body" autofocus />
                </Field>
                <Field id="new-customer-kind" label="Kind">
                    <select id="new-customer-kind" v-model="draft.kind" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body">
                        <option value="individual">Individual</option>
                        <option value="organization">Organisation</option>
                    </select>
                </Field>
                <Field id="new-customer-tin" label="Tax ID" optional :error="createErrors.tax_id">
                    <input id="new-customer-tin" v-model="draft.tax_id" class="h-8 rounded-control border border-line-control bg-surface px-2 text-body" />
                </Field>
                <div class="flex justify-end gap-2">
                    <button type="button" class="h-8 rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="creating = false">Cancel</button>
                    <button type="submit" :disabled="saving" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50">Create customer</button>
                </div>
            </form>
        </Drawer>
    </div>
</template>
