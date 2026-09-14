<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DateInput from '@/components/forms/DateInput.vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { buildTree, canMoveUnder, flatten } from '@/lib/hierarchyTree';

/**
 * Distribution design note §6 "hierarchy tree with drag-transfer + effective date". Drag a producer onto its new manager (or onto "Top of the
 * tree"), or select it and press M; either way the move asks for the date it takes effect, because payouts before that date keep the old tree.
 */
interface Node { id: string; code: string; name: string; type: string; status: string; level: string | null; parent_id: string | null }
const props = defineProps<{
    on: string;
    schemes: { id: string; code: string; name: string }[];
    scheme: string | null;
    levels: { level_code: string; rank: number; label: string }[];
    nodes: Node[];
    can: { move: boolean };
}>();

const tree = computed(() => buildTree(props.nodes));
const collapsed = ref(new Set<string>());
const rows = computed(() => flatten(tree.value, collapsed.value));
const selected = ref<string | null>(null);
const dragging = ref<string | null>(null);
const dropTarget = ref<string | 'root' | null>(null);
const moving = ref(false);
const form = useForm({ producer_id: '', parent_id: '', level_code: '', effective_from: '' });
const levelLabel = (code: string | null) => props.levels.find((l) => l.level_code === code)?.label ?? code ?? 'No level';
const byId = computed(() => new Map(props.nodes.map((n) => [n.id, n])));
const movingNode = computed(() => byId.value.get(form.producer_id));

function reload(changes: Record<string, string>): void {
    router.get('/distribution/hierarchy', { on: props.on, scheme: props.scheme ?? '', ...changes }, { preserveState: true, replace: true });
}
function toggle(id: string): void {
    const next = new Set(collapsed.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    collapsed.value = next;
}
function startMove(producerId: string, parentId: string | null): void {
    const node = byId.value.get(producerId);
    form.defaults({ producer_id: producerId, parent_id: parentId ?? '', level_code: node?.level ?? '', effective_from: props.on });
    form.reset();
    form.clearErrors();
    moving.value = true;
}
function drop(parentId: string | null): void {
    const producerId = dragging.value;
    dragging.value = null;
    dropTarget.value = null;
    if (producerId && canMoveUnder(tree.value, producerId, parentId)) startMove(producerId, parentId);
}
function onKey(event: KeyboardEvent): void {
    const index = rows.value.findIndex((r) => r.id === selected.value);
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const next = rows.value[Math.min(rows.value.length - 1, Math.max(0, index + (event.key === 'ArrowDown' ? 1 : -1)))];
        selected.value = next?.id ?? null;
        document.getElementById(`node-${selected.value}`)?.focus();
    } else if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && selected.value) {
        const isCollapsed = collapsed.value.has(selected.value);
        if ((event.key === 'ArrowLeft') !== isCollapsed) toggle(selected.value);
    } else if (event.key.toLowerCase() === 'm' && selected.value && props.can.move) {
        event.preventDefault();
        startMove(selected.value, byId.value.get(selected.value)?.parent_id ?? null);
    }
}
</script>

<template>
    <AppLayout help="distribution" title="Hierarchy">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div>
                <Link href="/distribution/producers" class="text-dense text-accent-text hover:underline">Producers</Link>
                <h1 class="text-title font-semibold">Hierarchy on {{ formatDate(on) }}</h1>
                <p class="text-ui text-ink-2">Drag a producer onto its new manager, or select it and press M. Moves take effect from the date you choose.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <Field id="tree_scheme" label="Levels of"><SelectInput id="tree_scheme" :model-value="scheme ?? ''" :options="schemes.map((s) => ({ value: s.id, label: s.code }))" @update:model-value="(v) => reload({ scheme: String(v) })" /></Field>
                <Field id="tree_on" label="On"><DateInput :model-value="on" @update:model-value="(v) => v && reload({ on: v })" /></Field>
            </div>
        </div>

        <div class="max-w-[1000px] border border-line" role="tree" aria-label="Producer hierarchy" @keydown="onKey">
            <div
                v-if="can.move"
                class="flex h-(--row-h) items-center border-b border-line px-3 text-dense text-ink-2"
                :class="{ 'bg-accent-soft text-ink': dropTarget === 'root' }"
                @dragover.prevent="dropTarget = 'root'" @dragleave="dropTarget = null" @drop.prevent="drop(null)"
            >Top of the tree (reports to nobody)</div>
            <div
                v-for="row in rows"
                :id="`node-${row.id}`"
                :key="row.id"
                role="treeitem"
                :aria-level="row.depth + 1"
                :aria-expanded="row.hasChildren ? !collapsed.has(row.id) : undefined"
                :aria-selected="selected === row.id"
                :tabindex="selected === row.id || (selected === null && row === rows[0]) ? 0 : -1"
                :draggable="can.move"
                class="flex h-(--row-h) items-center gap-2 border-b border-line pr-3 text-ui outline-none last:border-b-0 focus-visible:ring-2 focus-visible:ring-focus"
                :class="{ 'bg-accent-soft': selected === row.id || dropTarget === row.id, 'opacity-50': dragging === row.id }"
                :style="{ paddingLeft: `${12 + row.depth * 24}px` }"
                @click="selected = row.id"
                @dragstart="dragging = row.id"
                @dragend="dragging = null; dropTarget = null"
                @dragover.prevent="dragging && canMoveUnder(tree, dragging, row.id) && (dropTarget = row.id)"
                @dragleave="dropTarget === row.id && (dropTarget = null)"
                @drop.prevent="drop(row.id)"
            >
                <button v-if="row.hasChildren" type="button" class="inline-flex size-5 items-center justify-center text-ink-2 hover:text-ink" :aria-label="collapsed.has(row.id) ? 'Expand' : 'Collapse'" tabindex="-1" @click.stop="toggle(row.id)">
                    <component :is="collapsed.has(row.id) ? ChevronRight : ChevronDown" :size="16" :stroke-width="1.5" />
                </button>
                <span v-else class="size-5" />
                <Link :href="`/distribution/producers/${row.id}`" class="font-medium text-accent-text hover:underline" @click.stop>{{ row.code }}</Link>
                <span class="truncate">{{ row.row.name }}</span>
                <span class="ml-auto text-dense text-ink-2">{{ levelLabel(row.row.level) }}</span>
                <StatusBadge v-if="row.row.status !== 'active'" :status="row.row.status" />
                <Button v-if="can.move" variant="ghost" size="sm" tabindex="-1" @click.stop="startMove(row.id, row.row.parent_id)">Move…</Button>
            </div>
            <p v-if="rows.length === 0" class="px-3 py-6 text-ui text-ink-2">No producers yet. Add them under Producers.</p>
        </div>

        <Drawer v-model:open="moving" :title="movingNode ? `Move ${movingNode.code}` : 'Move'">
            <FormLayout submit-label="Move" :dirty="true" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="form.post('/distribution/hierarchy/moves', { preserveScroll: true, onSuccess: () => (moving = false) })" @cancel="moving = false">
                <Field id="move_parent" label="Reports to" optional :error="form.errors.parent_id">
                    <SelectInput id="move_parent" v-model="form.parent_id" placeholder="Nobody" :options="nodes.filter((n) => n.id === form.parent_id || canMoveUnder(tree, form.producer_id, n.id)).map((n) => ({ value: n.id, label: `${n.code} · ${n.name}` }))" />
                </Field>
                <Field id="move_level" label="Level" optional :error="form.errors.level_code">
                    <SelectInput id="move_level" v-model="form.level_code" placeholder="No level" :options="levels.map((l) => ({ value: l.level_code, label: l.label }))" />
                </Field>
                <Field id="move_from" label="From" hint="Commission on premium before this date keeps the old tree." :error="form.errors.effective_from"><DateInput v-model="form.effective_from" /></Field>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
