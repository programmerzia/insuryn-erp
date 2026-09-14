<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { confirmAction } from '@/lib/confirm';
import { lastRanLine, type NightlyJob, type NightlyJobsPanel, runSummary } from '@/lib/nightlyJobs';

/**
 * Gap fix GA-05: what runs every night without anyone pressing a button — policies becoming Active, expiry, earning, payment reminders and lapse,
 * renewals — with when each job last ran and what it did. Finance can run one now for this company.
 */
const props = defineProps<{ panel: NightlyJobsPanel }>();
const running = ref<string | null>(null);

async function runNow(job: NightlyJob): Promise<void> {
    const ok = await confirmAction({ title: `Run ${job.label.toLowerCase()} now?`, body: `${job.does} It runs for this company as of today, as it does every night at ${job.at}.`, confirmLabel: 'Run now' });
    if (!ok) return;
    router.post(`/close/jobs/${job.key}/run`, {}, { preserveScroll: true, onStart: () => (running.value = job.key), onFinish: () => (running.value = null) });
}
void props;
</script>

<template>
    <div class="grid gap-3">
        <p class="text-ui text-ink-2">These jobs run every night on the company clock ({{ panel.zone }}). A job that has not run leaves policies Issued, reminders unsent and renewals unprepared.</p>
        <ul class="rounded-panel border border-line" aria-label="Nightly jobs">
            <li v-for="job in panel.jobs" :key="job.key" class="grid gap-1 border-b border-line px-3 py-2 last:border-b-0">
                <div class="flex items-center gap-2">
                    <p class="min-w-0 flex-1 text-ui font-medium">{{ job.label }} <span class="font-normal text-ink-2 tabular-nums">· {{ job.at }}</span></p>
                    <StatusBadge v-if="job.last" :status="job.last.status" />
                </div>
                <p class="text-dense text-ink-2">{{ job.does }}</p>
                <p class="text-dense" :class="job.last === null ? 'text-warn' : 'text-ink-2'">
                    {{ lastRanLine(job.last) }}<template v-if="job.last && runSummary(job.last.summary)"> · {{ runSummary(job.last.summary) }}</template>
                </p>
                <p v-if="job.last?.error" class="text-dense text-danger" role="alert">{{ job.last.error }}</p>
                <button
                    v-if="panel.can_run"
                    type="button"
                    class="h-7 justify-self-start rounded-control border border-line-control px-2 text-ui hover:bg-surface-2 disabled:opacity-50"
                    :disabled="running !== null"
                    @click="runNow(job)"
                >
                    {{ running === job.key ? 'Running…' : 'Run now' }}
                </button>
            </li>
        </ul>
    </div>
</template>
