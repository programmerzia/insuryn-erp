<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import DataTable from '@/components/table/DataTable.vue';
import type { DataColumn } from '@/components/table/types';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

interface PreviewLine { role_code: string | null; account_code: string; account_name: string; side: string; amount_minor: number; currency: string }
interface DemoResult {
    kind: 'preview' | 'posted';
    data: {
        posts?: boolean; balanced?: boolean; reason?: string | null; lines?: PreviewLine[];
        event_id?: string; status?: string; journal_id?: string; journal_number?: string | null;
    };
}

interface Endpoint { method: string; path: string; summary: string; auth: string; notes?: string }

const props = defineProps<{
    tenant: { id: string; slug: string };
    sample: Record<string, unknown>;
    integrationUsers: Array<{ id: string; email: string; name: string }>;
    openapiPath: string;
    endpoints: Endpoint[];
    implementationSteps: string[];
    integrationEmail: string;
    can: { manageIntegration: boolean };
    result: DemoResult | null;
}>();

type Tab = 'try' | 'reference' | 'integrate';
const tab = ref<Tab>('try');

const body = useForm({ body: JSON.stringify(props.sample, null, 2) });
const integration = useForm({ name: 'Customer API', email: '', password: '' });

const parsed = computed(() => {
    try {
        return JSON.parse(body.body) as Record<string, unknown>;
    } catch {
        return null;
    }
});

const endpointColumns: DataColumn<Endpoint>[] = [
    { id: 'method', header: 'Method', value: (r) => r.method, width: 80 },
    { id: 'path', header: 'Path', value: (r) => r.path, width: 300 },
    { id: 'summary', header: 'Purpose', value: (r) => (r.notes ? `${r.summary} — ${r.notes}` : r.summary), width: 320, muted: true },
    { id: 'auth', header: 'Auth', value: (r) => r.auth, width: 160, muted: true },
];

const curlToken = computed(() => `curl -X POST http://localhost:8000/api/v1/tokens \\
  -H "Content-Type: application/json" \\
  -H "X-Tenant: ${props.tenant.slug || props.tenant.id}" \\
  -d '{"email":"${props.integrationEmail}","password":"ChangeMe123!","device_name":"demo"}'`);

const curlPost = computed(() => `curl -X POST "http://localhost:8000/api/v1/events?sync=1" \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Content-Type: application/json" \\
  -H "X-Tenant: ${props.tenant.slug || props.tenant.id}" \\
  -d '${JSON.stringify(props.sample)}'`);

const tabClass = (value: Tab) => [
    'inline-flex h-8 items-center rounded-control px-3 text-ui font-medium transition-colors',
    tab.value === value ? 'bg-accent text-accent-ink' : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
];
</script>

<template>
    <AppLayout help="events" title="Ledger API demo">
        <div class="mx-auto grid min-w-0 max-w-[960px] gap-5">
            <p class="text-ui text-ink-2">
                Their system posts accounting events over HTTP; the ledger turns them into balanced journals. Preview and post the same payload the API accepts, then open the journal in the books.
            </p>

            <nav class="flex w-fit flex-wrap gap-1 rounded-control border border-line bg-surface-2 p-0.5" aria-label="Ledger API demo sections">
                <button type="button" :class="tabClass('try')" @click="tab = 'try'">Try it</button>
                <button type="button" :class="tabClass('reference')" @click="tab = 'reference'">API reference</button>
                <button type="button" :class="tabClass('integrate')" @click="tab = 'integrate'">How to integrate</button>
            </nav>

            <!-- Try it -->
            <div v-show="tab === 'try'" class="grid gap-4">
                <section class="rounded-panel border border-line bg-surface p-4">
                    <h2 class="text-ui font-semibold">Event payload</h2>
                    <p class="mt-1 text-dense text-ink-2">Same JSON shape as <code class="rounded-control bg-surface-2 px-1">POST /api/v1/events</code>. Amounts in minor units (KES × 100).</p>
                    <FormLayout submit-label="Preview lines" :dirty="body.isDirty" :processing="body.processing" :error="(body.errors as Record<string, string>).body" class="mt-3" @submit="body.post('/accounting/ledger-api/preview')" @cancel="body.reset()">
                        <textarea v-model="body.body" rows="14" class="w-full min-w-0 rounded-control border border-line-control bg-surface px-3 py-2 font-mono text-dense" spellcheck="false" />
                        <template #actions>
                            <button type="button" class="h-8 rounded-control border border-line px-3 text-ui hover:bg-surface-2" :disabled="body.processing || !parsed" @click="body.post('/accounting/ledger-api/post')">Post now (sync)</button>
                        </template>
                    </FormLayout>
                </section>

                <section v-if="result" class="rounded-panel border border-line bg-surface p-4">
                    <h2 class="text-ui font-semibold">{{ result.kind === 'preview' ? 'Preview result' : 'Posted' }}</h2>
                    <template v-if="result.kind === 'preview'">
                        <p v-if="result.data.reason" class="mt-2 text-ui text-danger">{{ result.data.reason }}</p>
                        <div v-if="result.data.lines?.length" class="mt-3 overflow-x-auto border border-line">
                            <table class="w-full min-w-[480px] table-fixed border-separate border-spacing-0 text-dense">
                                <colgroup><col class="w-[50%]"><col class="w-20"><col></colgroup>
                                <thead class="bg-surface-2 text-left text-ink-2"><tr><th class="px-3 py-2">Account</th><th class="px-3 py-2">Side</th><th class="px-3 py-2 text-right">Amount</th></tr></thead>
                                <tbody>
                                    <tr v-for="(line, i) in result.data.lines" :key="i" class="border-t border-line">
                                        <td class="px-3 py-2">{{ line.account_code }} — {{ line.account_name }}</td>
                                        <td class="px-3 py-2">{{ line.side }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(line.amount_minor, line.currency) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template v-else>
                        <p class="mt-2 text-ui">Event {{ result.data.event_id }} — {{ result.data.status }}</p>
                        <Link v-if="result.data.journal_id" :href="`/accounting/journals/${result.data.journal_id}`" class="mt-2 inline-block text-ui text-accent-text hover:underline">
                            Open journal {{ result.data.journal_number }}
                        </Link>
                    </template>
                </section>
            </div>

            <!-- API reference -->
            <div v-show="tab === 'reference'" class="grid gap-4">
                <section class="rounded-panel border border-line bg-surface p-4">
                    <h2 class="text-ui font-semibold">Ledger API v1 endpoints</h2>
                    <p class="mt-1 text-dense text-ink-2">
                        OpenAPI: <a :href="openapiPath" class="text-accent-text hover:underline" target="_blank" rel="noopener">{{ openapiPath }}</a>
                        · Sample payloads: <code class="rounded-control bg-surface-2 px-1">docs/guide/ledger-event-catalogue-kes.md</code>
                    </p>
                    <div class="mt-3 min-w-0 border border-line">
                        <DataTable
                            id="ledger-api-endpoints"
                            label="Ledger API endpoints"
                            :columns="endpointColumns"
                            :rows="endpoints"
                            :row-key="(r) => `${r.method}:${r.path}`"
                            :url-sync="false"
                            compact-toolbar
                            empty-text="No endpoints configured."
                        />
                    </div>
                    <p class="mt-3 text-dense text-ink-2">
                        Fixed assets, payables and payroll use the same event API — e.g. <code class="rounded-control bg-surface-2 px-1">FA_ACQUIRED</code>, <code class="rounded-control bg-surface-2 px-1">AP_BILL_POSTED</code>, <code class="rounded-control bg-surface-2 px-1">PAYROLL_PAID</code>. Staff screens and API posts share one GL.
                    </p>
                </section>
            </div>

            <!-- How to integrate -->
            <div v-show="tab === 'integrate'" class="grid gap-4">
                <section class="rounded-panel border border-line bg-surface p-4">
                    <h2 class="text-ui font-semibold">Integration checklist</h2>
                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-ui">
                        <li v-for="(step, i) in implementationSteps" :key="i">{{ step }}</li>
                    </ol>
                    <p class="mt-4 rounded-control border border-line bg-surface-2 px-3 py-2 text-dense text-ink-2">
                        Demo user (after <code class="rounded-control bg-surface px-1">erp:demo</code>): <strong>{{ integrationEmail }}</strong> / ChangeMe123! · Header <code class="rounded-control bg-surface px-1">X-Tenant: {{ tenant.slug || tenant.id }}</code>
                    </p>
                </section>

                <section class="rounded-panel border border-line bg-surface p-4">
                    <h2 class="text-ui font-semibold">HTTP examples (curl)</h2>
                    <p class="mt-1 text-dense text-ink-2">Obtain a token, then post an event. Replace the host if not running locally.</p>
                    <pre class="mt-3 max-w-full overflow-x-auto rounded-control border border-line bg-surface-2 p-3 font-mono text-dense whitespace-pre">{{ curlToken }}</pre>
                    <pre class="mt-3 max-w-full overflow-x-auto rounded-control border border-line bg-surface-2 p-3 font-mono text-dense whitespace-pre">{{ curlPost }}</pre>
                </section>

                <section v-if="can.manageIntegration" class="rounded-panel border border-line bg-surface p-4">
                    <h2 class="text-ui font-semibold">Integration users</h2>
                    <ul v-if="integrationUsers.length" class="mt-2 list-disc pl-5 text-ui">
                        <li v-for="u in integrationUsers" :key="u.id">{{ u.name }} — {{ u.email }}</li>
                    </ul>
                    <p v-else class="mt-2 text-dense text-ink-2">No integration users yet.</p>
                    <FormLayout submit-label="Create integration user" :dirty="integration.isDirty" :processing="integration.processing" :error="(integration.errors as Record<string, string>).integration" class="mt-4" @submit="integration.post('/accounting/ledger-api/integration-users')" @cancel="integration.reset()">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <input v-model="integration.name" placeholder="Name" class="h-8 rounded-control border border-line-control bg-surface px-3 text-ui" />
                            <input v-model="integration.email" type="email" placeholder="Email" class="h-8 rounded-control border border-line-control bg-surface px-3 text-ui" />
                            <input v-model="integration.password" type="password" placeholder="Password (min 12)" class="h-8 rounded-control border border-line-control bg-surface px-3 text-ui" />
                        </div>
                    </FormLayout>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
