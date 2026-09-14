<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Armchair, Banknote, BookOpen, Calculator, ClipboardCheck, CornerDownLeft, FileClock, FileInput, FileSpreadsheet, FileText, Handshake, History, IdCard, MousePointerClick, Search, Send, ShieldAlert, Truck, User, UsersRound, Zap } from 'lucide-vue-next';
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, VisuallyHidden } from 'reka-ui';
import { type Component, computed, nextTick, onMounted, ref, watch } from 'vue';
import Kbd from '@/components/ui/Kbd.vue';
import { buildCommands, type Command, rankCommands, rememberRecent } from '@/lib/commands';
import { requestJson } from '@/lib/http';
import { pageActions, rankPageActions } from '@/lib/pageActions';
import { paletteOpen } from '@/lib/palette';
import { savePreference, usePreferences, type Recent } from '@/lib/preferences';
import { SHORTCUTS, shortcutKeys } from '@/lib/shortcuts';
import type { SharedProps } from '@/types/shared';

/**
 * Ctrl+K command palette (UX brief §4): navigate ("go to claims"), act ("new receipt", "lock period Sep 2026"), find ("POL-1042", "cheque 88231").
 * Fuzzy, recent-first. ↑↓ move, Enter runs, Esc closes. Records and period actions come from GET /search.
 */
interface Entry {
    key: string;
    group: string;
    label: string;
    detail?: string;
    icon: Component;
    shortcut?: string;
    run: () => void;
}
interface SearchResult {
    kind: string;
    label: string;
    detail: string;
    href: string;
}

const page = usePage<SharedProps>();
const preferences = usePreferences();
const query = ref('');
const active = ref(0);
const results = ref<SearchResult[]>([]);
const searching = ref(false);
const mode = ref<'commands' | 'shortcuts'>('commands');
const list = ref<HTMLElement | null>(null);
let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

const kindIcons: Record<string, Component> = { policy: FileText, claim: ShieldAlert, receipt: Banknote, customer: User, journal: BookOpen, action: Zap,
    quotation: Calculator, proposal: ClipboardCheck, cover_note: FileClock, producer: UsersRound,
    supplier_bill: FileInput, payment_run: Send, supplier: Truck, treaty: Handshake, fixed_asset: Armchair, employee: IdCard, reinsurer_statement: FileSpreadsheet };
const kindWords: Record<string, string> = { policy: 'Policy', claim: 'Claim', receipt: 'Receipt', customer: 'Customer', journal: 'Journal', action: 'Action',
    quotation: 'Quotation', proposal: 'Proposal', cover_note: 'Cover note', producer: 'Producer',
    supplier_bill: 'Supplier bill', payment_run: 'Payment run', supplier: 'Supplier', treaty: 'Treaty', fixed_asset: 'Fixed asset', employee: 'Employee', reinsurer_statement: 'Reinsurer statement' };

const commands = computed(() => buildCommands(page.props.auth.permissions ?? []));

function visit(item: Recent): void {
    savePreference('recents', rememberRecent(preferences.recents, item), 0);
    paletteOpen.value = false;
    router.visit(item.href);
}

function runCommand(command: Command): void {
    if (command.special === 'shortcuts') {
        mode.value = 'shortcuts';
        return;
    }
    paletteOpen.value = false;
    if (command.special === 'toggle-sidebar') {
        savePreference('sidebar_collapsed', !preferences.sidebar_collapsed);
    } else if (command.preference) {
        savePreference(command.preference.key, command.preference.value, 0);
    } else if (command.href) {
        visit({ kind: 'navigate', label: command.label, href: command.href });
    }
}

const entries = computed<Entry[]>(() => {
    const typed = query.value.trim();
    const rankedCommands = rankCommands(commands.value, typed, preferences.recents).slice(0, typed ? 8 : 12);
    const recentRecords = typed === '' ? preferences.recents.filter((r) => r.kind !== 'navigate').slice(0, 5) : [];
    // GA-29: what the open page allows (endorse, cancel, renew, refund, cover note), first.
    const onPage = pageActions.value ? rankPageActions(pageActions.value.actions, typed).slice(0, 8) : [];
    return [
        ...onPage.map((a): Entry => ({ key: `page-${a.id}`, group: pageActions.value?.group ?? 'This page', label: a.label, icon: MousePointerClick,
            run: () => (a.href ? visit({ kind: 'navigate', label: a.label, href: a.href }) : ((paletteOpen.value = false), a.run?.())) })),
        ...recentRecords.map((r): Entry => ({ key: `recent-${r.href}`, group: 'Recent', label: r.label, detail: kindWords[r.kind], icon: History, run: () => visit(r) })),
        ...results.value.filter((r) => r.kind === 'action').map((r): Entry => ({ key: `action-${r.label}`, group: 'Actions', label: r.label, detail: r.detail, icon: Zap, run: () => visit(r) })),
        ...rankedCommands.map((c): Entry => ({ key: c.id, group: c.group, label: c.label, icon: c.icon, shortcut: c.shortcut ? shortcutKeys(c.shortcut) : undefined, run: () => runCommand(c) })),
        ...results.value.filter((r) => r.kind !== 'action').map((r): Entry => ({ key: `${r.kind}-${r.href}`, group: 'Records', label: r.label, detail: `${kindWords[r.kind] ?? r.kind} · ${r.detail}`, icon: kindIcons[r.kind] ?? Search, run: () => visit(r) })),
    ];
});

const groups = computed(() => {
    const order: string[] = [];
    const byGroup = new Map<string, { entry: Entry; index: number }[]>();
    entries.value.forEach((entry, index) => {
        if (!byGroup.has(entry.group)) {
            order.push(entry.group);
            byGroup.set(entry.group, []);
        }
        byGroup.get(entry.group)?.push({ entry, index });
    });
    return order.map((name) => ({ name, items: byGroup.get(name) ?? [] }));
});

watch(query, (value) => {
    active.value = 0;
    clearTimeout(timer);
    controller?.abort();
    const typed = value.trim();
    // "cheque 88231" searches for the reference itself
    const term = typed.replace(/^(cheque|chq|receipt|policy|claim|journal|quotation|quote|proposal|cover note|producer|agent|bill|supplier|payment run|treaty|asset|employee)\s+/i, '');
    if (term.length < 2) {
        results.value = [];
        searching.value = false;
        return;
    }
    searching.value = true;
    timer = setTimeout(async () => {
        controller = new AbortController();
        try {
            const response = await requestJson<{ results: SearchResult[] }>('GET', `/search?q=${encodeURIComponent(/lock|close|reopen|period/i.test(typed) ? typed : term)}`, undefined, controller.signal);
            results.value = response.results;
        } catch {
            // aborted by the next keystroke, or offline: keep the command matches
        } finally {
            searching.value = false;
        }
    }, 150);
});

// GA-29: every opening starts from an empty query, whether the palette was closed with Esc, a click outside or by running a command.
function reset(): void {
    clearTimeout(timer);
    controller?.abort();
    query.value = '';
    mode.value = 'commands';
    results.value = [];
    searching.value = false;
    active.value = 0;
}
watch(paletteOpen, (open) => open && reset());
onMounted(reset);

function move(delta: number): void {
    const count = entries.value.length;
    if (count === 0) return;
    active.value = (active.value + delta + count) % count;
    void nextTick(() => list.value?.querySelector(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }));
}
</script>

<template>
    <DialogRoot v-model:open="paletteOpen">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-scrim" />
            <DialogContent class="fixed top-[12vh] left-1/2 z-50 flex max-h-[70vh] w-[640px] max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-col overflow-hidden rounded-panel border border-line bg-surface text-ink shadow-float outline-none" :aria-describedby="undefined">
                <VisuallyHidden><DialogTitle>Command palette</DialogTitle></VisuallyHidden>
                <template v-if="mode === 'commands'">
                    <div class="flex items-center gap-2 border-b border-line px-3">
                        <Search :size="16" :stroke-width="1.5" class="text-ink-2" aria-hidden="true" />
                        <input
                            v-model="query"
                            class="h-11 min-w-0 flex-1 bg-transparent text-body outline-none placeholder:text-ink-2"
                            placeholder="Go to, run a command, or find a policy, quote, cover note, claim, receipt, customer or vehicle"
                            role="combobox"
                            aria-expanded="true"
                            aria-controls="palette-results"
                            :aria-activedescendant="entries[active] ? `palette-${active}` : undefined"
                            autocomplete="off"
                            @keydown.down.prevent="move(1)"
                            @keydown.up.prevent="move(-1)"
                            @keydown.enter.prevent="entries[active]?.run()"
                        />
                        <span v-if="searching" class="text-dense text-ink-2" role="status">Searching…</span>
                    </div>
                    <div id="palette-results" ref="list" class="min-h-0 flex-1 overflow-y-auto p-1" role="listbox" aria-label="Results">
                        <div v-for="group in groups" :key="group.name" role="group" :aria-label="group.name">
                            <p class="px-2 pt-2 pb-1 text-dense text-ink-2">{{ group.name }}</p>
                            <div
                                v-for="{ entry, index } in group.items"
                                :id="`palette-${index}`"
                                :key="entry.key"
                                :data-index="index"
                                role="option"
                                :aria-selected="index === active"
                                class="flex h-9 cursor-default items-center gap-2.5 rounded-control px-2 text-ui"
                                :class="index === active ? 'bg-accent-soft text-ink' : 'text-ink'"
                                @mousemove="active = index"
                                @click="entry.run()"
                            >
                                <component :is="entry.icon" :size="16" :stroke-width="1.5" class="shrink-0 text-ink-2" aria-hidden="true" />
                                <span class="shrink-0">{{ entry.label }}</span>
                                <span v-if="entry.detail" class="min-w-0 truncate text-ink-2">{{ entry.detail }}</span>
                                <Kbd v-if="entry.shortcut" class="ml-auto" :keys="entry.shortcut" />
                                <CornerDownLeft v-else-if="index === active" :size="14" :stroke-width="1.5" class="ml-auto shrink-0 text-ink-2" aria-hidden="true" />
                            </div>
                        </div>
                        <p v-if="entries.length === 0 && !searching" class="px-3 py-6 text-center text-ui text-ink-2">
                            Nothing matches “{{ query }}”. Try a policy, claim or receipt number, a customer name or a cheque number.
                        </p>
                    </div>
                    <div class="flex items-center gap-4 border-t border-line px-3 py-1.5 text-dense text-ink-2">
                        <span class="inline-flex items-center gap-1"><Kbd keys="↑" /><Kbd keys="↓" /> move</span>
                        <span class="inline-flex items-center gap-1"><Kbd keys="Enter" /> open</span>
                        <span class="inline-flex items-center gap-1"><Kbd keys="Esc" /> close</span>
                    </div>
                </template>
                <template v-else>
                    <div class="flex items-center justify-between border-b border-line px-4 py-3">
                        <h2 class="text-section font-semibold">Keyboard shortcuts</h2>
                        <button type="button" class="text-ui text-accent-text hover:underline" @click="mode = 'commands'">Back to commands</button>
                    </div>
                    <dl class="grid min-h-0 grid-cols-[1fr_auto] gap-x-6 gap-y-2 overflow-y-auto px-4 py-3 text-ui">
                        <template v-for="(shortcut, id) in SHORTCUTS" :key="id">
                            <dt>{{ shortcut.label }}</dt>
                            <dd><Kbd :keys="shortcut.keys" /></dd>
                        </template>
                    </dl>
                </template>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
