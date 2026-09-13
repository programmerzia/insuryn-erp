<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, Lock } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { formatDate } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';

/**
 * UX brief §6.5 month-end close: the task checklist in order with owners, what each waits for, results and progress; each task opens the
 * queue where its exceptions are; the lock stays disabled with the reason until the close is clean, and asks before locking.
 */
interface Task { id: string; code: string; order_no: number; owner_role: string; status: string; depends_on: string[]; summary: string | null; done_at: string | null; blocked_by?: string[] }
const props = defineProps<{
    run: { id: string; status: string; period: string; period_status: string; started_at: string; completed_at: string | null; starts?: string; ends?: string };
    tasks: Task[];
    lock?: { ready: boolean; reason: string | null };
}>();

const { can } = usePermissions();
const notes = reactive<Record<string, string>>({});
const expanded = ref<string | null>(null);
const words = (code: string) => code.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const month = computed(() => (props.run.starts ? new Date(`${props.run.starts}T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : props.run.period));
const done = computed(() => props.tasks.filter((t) => t.status === 'done' || t.status === 'skipped').length);
const lockTask = computed(() => props.tasks.find((t) => t.code === 'period_lock'));
const checklist = computed(() => props.tasks.filter((t) => t.code !== 'period_lock'));
const end = computed(() => props.run.ends ?? '');
const queueFor = (code: string): { label: string; href: string } | null =>
    ({
        premium_earning: { label: 'Premium register', href: `/reports/premium-register?from=${props.run.starts}&to=${end.value}` },
        suspense_review: { label: 'Suspense', href: '/suspense' },
        bank_reconciliation: { label: 'Bank matching', href: '/bank' },
        premium_reconciliation: { label: 'Receivable ageing', href: `/reports/receivable-ageing?as_of=${end.value}` },
        claims_reconciliation: { label: 'Outstanding claims', href: `/reports/outstanding-claims?as_of=${end.value}` },
        commission_reconciliation: { label: 'Commission', href: '/commission' },
        accruals: { label: 'New manual journal', href: '/accounting/journals/create' },
        trial_balance: { label: 'Trial balance', href: `/accounting/trial-balance?as_of=${end.value}` },
        financial_statements: { label: 'Balance sheet', href: `/reports/balance-sheet?as_of=${end.value}` },
    })[code] ?? null;
const runnable = (t: Task) => props.run.status === 'running' && (t.status === 'pending' || t.status === 'blocked');

function execute(task: Task): void {
    router.post(`/close/tasks/${task.id}/execute`, { note: notes[task.id] ?? '' }, { preserveScroll: true, onSuccess: () => (expanded.value = null) });
}
function skip(task: Task): void {
    router.post(`/close/tasks/${task.id}/skip`, { reason: notes[task.id] ?? '' }, { preserveScroll: true, onSuccess: () => (expanded.value = null) });
}
async function lockPeriod(): Promise<void> {
    if (!lockTask.value || !props.lock?.ready) return;
    const ok = await confirmAction({ title: `Lock ${month.value}?`, body: 'Nobody can post into a locked month. Reopening it later needs a reason and the right approval.', confirmLabel: `Lock ${month.value}` });
    if (ok) router.post(`/close/tasks/${lockTask.value.id}/execute`, { note: 'Locked from the close checklist' }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="`Close ${month}`">
        <div class="grid max-w-[1040px] gap-4">
            <header class="flex flex-wrap items-end gap-x-6 gap-y-2">
                <div>
                    <p class="text-ui text-ink-2"><Link href="/close" class="hover:underline">Month-end close</Link> ›</p>
                    <h1 class="text-title font-semibold">Close {{ month }}</h1>
                    <p class="text-ui text-ink-2">Period {{ words(run.period_status).toLowerCase() }} · close {{ words(run.status).toLowerCase() }} · started {{ formatDate(run.started_at) }}</p>
                </div>
                <div class="min-w-64 flex-1">
                    <p class="text-ui"><span class="tabular-nums font-medium">{{ done }}</span> of <span class="tabular-nums">{{ tasks.length }}</span> tasks done or skipped</p>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-2" role="progressbar" :aria-valuenow="done" :aria-valuemax="tasks.length" aria-label="Close progress">
                        <div class="h-full bg-ok" :style="{ width: `${(done / Math.max(tasks.length, 1)) * 100}%` }" />
                    </div>
                </div>
            </header>

            <ol class="rounded-panel border border-line" aria-label="Close tasks">
                <li v-for="task in checklist" :key="task.id" class="border-b border-line last:border-b-0">
                    <div class="grid grid-cols-[28px_minmax(0,1fr)_150px_140px_auto] items-center gap-3 px-4 py-2 text-ui">
                        <span class="num inline-flex size-6 items-center justify-center rounded-full border text-dense" :class="task.status === 'done' || task.status === 'skipped' ? 'border-ok text-ok' : 'border-line-control text-ink-2'">
                            <Check v-if="task.status === 'done'" :size="12" :stroke-width="2" aria-hidden="true" /><template v-else>{{ task.order_no }}</template>
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium">{{ words(task.code) }}</p>
                            <p v-if="task.summary" class="truncate text-dense text-ink-2" :title="task.summary">{{ task.summary }}</p>
                            <p v-else-if="task.blocked_by?.length && runnable(task)" class="truncate text-dense text-warn">Waits for {{ task.blocked_by.join(', ') }}</p>
                        </div>
                        <span class="truncate text-ink-2">{{ words(task.owner_role) }}</span>
                        <StatusBadge :status="task.status" />
                        <div class="flex items-center justify-end gap-2">
                            <Link v-if="queueFor(task.code)" :href="queueFor(task.code)!.href" class="text-accent-text hover:underline">{{ queueFor(task.code)!.label }}</Link>
                            <button v-if="runnable(task)" type="button" class="h-7 rounded-control border border-line-control px-2 text-ui hover:bg-surface-2" :aria-expanded="expanded === task.id" @click="expanded = expanded === task.id ? null : task.id">Work on it</button>
                        </div>
                    </div>
                    <div v-if="expanded === task.id" class="flex flex-wrap items-center gap-2 bg-surface-2 px-4 py-2 pl-[3.25rem]">
                        <input v-model="notes[task.id]" class="h-8 min-w-64 flex-1 rounded-control border border-line-control bg-surface px-2 text-body" :placeholder="`Note, or the reason when skipping ${words(task.code).toLowerCase()}`" :aria-label="`Note for ${words(task.code)}`" />
                        <button type="button" class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50" :disabled="(task.blocked_by?.length ?? 0) > 0" :title="task.blocked_by?.length ? `Waits for ${task.blocked_by.join(', ')}` : undefined" @click="execute(task)">Run the task</button>
                        <button type="button" class="h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2 disabled:opacity-50" :disabled="!(notes[task.id] ?? '').trim()" @click="skip(task)">Skip with this reason</button>
                    </div>
                </li>
            </ol>

            <section class="flex flex-wrap items-center gap-3 rounded-panel border border-line px-4 py-3" aria-label="Lock the period">
                <Lock :size="16" :stroke-width="1.5" class="text-ink-2" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-ui font-medium">Lock {{ month }}</p>
                    <p class="text-dense" :class="lock?.ready ? 'text-ok' : 'text-ink-2'">{{ lock?.ready ? 'Every task is done and every subledger reconciles.' : (lock?.reason ?? lockTask?.summary ?? 'Locked.') }}</p>
                </div>
                <button
                    v-if="lockTask && run.status === 'running' && can('periods.lock')"
                    type="button"
                    class="h-8 rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover disabled:opacity-50"
                    :disabled="!lock?.ready"
                    :title="lock?.ready ? undefined : (lock?.reason ?? undefined)"
                    @click="lockPeriod"
                >
                    Lock the period
                </button>
            </section>
        </div>
    </AppLayout>
</template>
