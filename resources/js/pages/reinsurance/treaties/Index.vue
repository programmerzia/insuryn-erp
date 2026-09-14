<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import TextInput from '@/components/forms/TextInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Drawer from '@/components/ui/Drawer.vue';
import AppLayout from '@/layouts/AppLayout.vue';

/**
 * Reinsurance → Treaties (market gap G4): the treaties per class and underwriting year — quota share or surplus, commission and the SBC compulsory share — and
 * the reinsurers they cede to. Sadharan Bima Corporation (SBC), the state reinsurer, takes its compulsory share of every class first.
 */
interface TreatyRow { id: string; code: string; name: string; class: string; year: number; period: string; type: string; status: string; terms: string; commission: string; sbc_share: string; participants: string }
interface ReinsurerRow { id: string; code: string; name: string; rating: string | null; rating_agency: string | null; country: string; is_state_reinsurer: boolean; status: string }
const props = defineProps<{ treaties: TreatyRow[]; reinsurers: ReinsurerRow[]; canManage: boolean; currency: string }>();

const drawer = ref(false);
const form = useForm({ name: '', code: '', rating: '', rating_agency: '', country: 'BD', is_state_reinsurer: false });
const errors = computed(() => form.errors as Record<string, string>);
const hasSbc = computed(() => props.reinsurers.some((r) => r.is_state_reinsurer));
const types: Record<string, string> = { quota_share: 'Quota share', surplus: 'Surplus' };
function submit(): void {
    form.post('/reinsurance/reinsurers', { preserveScroll: true, onSuccess: () => { form.reset(); drawer.value = false; } });
}
</script>

<template>
    <AppLayout title="Treaties">
        <div class="max-w-[1200px]">
            <div class="mb-4 flex items-center gap-3">
                <h1 class="text-title font-semibold">Treaties</h1>
                <div class="ml-auto flex gap-2">
                    <Link href="/reinsurance/cessions" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Cessions</Link>
                    <Link href="/reinsurance/statements" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2">Reinsurer statements</Link>
                    <Link v-if="canManage" href="/reinsurance/treaties/create" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">New treaty</Link>
                </div>
            </div>
            <p class="mb-4 text-ui text-ink-2">Each policy is ceded when it is issued, endorsed or cancelled: first the SBC compulsory share, then the treaty for its class whose period covers the inception date.</p>

            <div class="mb-8 overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)">
                        <th class="border-b border-line px-3 text-left font-medium">Treaty</th><th class="border-b border-line px-3 text-left font-medium">Class</th>
                        <th class="border-b border-line px-3 text-left font-medium">Year</th><th class="border-b border-line px-3 text-left font-medium">Type</th>
                        <th class="border-b border-line px-3 text-left font-medium">Terms ({{ currency }})</th><th class="border-b border-line px-3 text-right font-medium">Commission %</th>
                        <th class="border-b border-line px-3 text-right font-medium">SBC share %</th><th class="border-b border-line px-3 text-left font-medium">Reinsurers</th>
                        <th class="border-b border-line px-3 text-left font-medium">Status</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="t in treaties" :key="t.id" class="h-(--row-h)">
                            <td class="border-b border-line px-3">
                                <Link v-if="canManage" :href="`/reinsurance/treaties/${t.id}`" class="font-medium text-accent-text hover:underline">{{ t.code }}</Link><span v-else class="font-medium">{{ t.code }}</span>
                                <span class="block text-ink-2">{{ t.name }}</span>
                            </td>
                            <td class="border-b border-line px-3">{{ t.class }}</td>
                            <td class="border-b border-line px-3">{{ t.year }}<span class="block text-ink-2">{{ t.period }}</span></td>
                            <td class="border-b border-line px-3">{{ types[t.type] ?? t.type }}</td>
                            <td class="border-b border-line px-3 tabular-nums">{{ t.terms }}</td>
                            <td class="num border-b border-line px-3">{{ t.commission }}</td>
                            <td class="num border-b border-line px-3">{{ t.sbc_share }}</td>
                            <td class="border-b border-line px-3">{{ t.participants }}</td>
                            <td class="border-b border-line px-3"><StatusBadge :status="t.status" /></td>
                        </tr>
                        <tr v-if="treaties.length === 0"><td colspan="9" class="px-3 py-6 text-center text-ui text-ink-2">No treaties yet: every risk is retained.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mb-2 flex items-center gap-3">
                <h2 class="text-section font-semibold">Reinsurers</h2>
                <button v-if="canManage" type="button" class="ml-auto h-8 rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="drawer = true">Add reinsurer</button>
            </div>
            <div class="max-w-[900px] overflow-x-auto border border-line">
                <table class="w-full border-separate border-spacing-0 text-dense">
                    <thead class="bg-surface-2 text-ink-2"><tr class="h-(--row-h)">
                        <th class="border-b border-line px-3 text-left font-medium">Code</th><th class="border-b border-line px-3 text-left font-medium">Reinsurer</th>
                        <th class="border-b border-line px-3 text-left font-medium">Rating</th><th class="border-b border-line px-3 text-left font-medium">Country</th>
                        <th class="border-b border-line px-3 text-left font-medium">Status</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="r in reinsurers" :key="r.id" class="h-(--row-h)">
                            <td class="border-b border-line px-3 font-medium">{{ r.code }}</td>
                            <td class="border-b border-line px-3">{{ r.name }}<span v-if="r.is_state_reinsurer" class="ml-2 rounded-control bg-surface-2 px-1.5 text-ink-2">State reinsurer · compulsory share</span></td>
                            <td class="border-b border-line px-3">{{ r.rating ? `${r.rating}${r.rating_agency ? ` (${r.rating_agency})` : ''}` : '—' }}</td>
                            <td class="border-b border-line px-3">{{ r.country }}</td>
                            <td class="border-b border-line px-3"><StatusBadge :status="r.status" /></td>
                        </tr>
                        <tr v-if="reinsurers.length === 0"><td colspan="5" class="px-3 py-6 text-center text-ui text-ink-2">No reinsurers yet. Add Sadharan Bima Corporation first.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Drawer v-model:open="drawer" title="Add a reinsurer">
            <FormLayout submit-label="Add reinsurer" :dirty="form.isDirty" :processing="form.processing" :error="errors.form" @submit="submit" @cancel="drawer = false">
                <Field id="ri_name" label="Name" :error="errors.name"><TextInput id="ri_name" v-model="form.name" /></Field>
                <Field id="ri_code" label="Code" hint="Short code on bordereaux and statements, like SBC." :error="errors.code"><TextInput id="ri_code" v-model="form.code" :maxlength="32" /></Field>
                <Field id="ri_rating" label="Security rating" optional :error="errors.rating"><TextInput id="ri_rating" v-model="form.rating" placeholder="A+" /></Field>
                <Field id="ri_agency" label="Rating agency" optional :error="errors.rating_agency"><TextInput id="ri_agency" v-model="form.rating_agency" placeholder="AM Best" /></Field>
                <Field id="ri_country" label="Country (2-letter code)" :error="errors.country"><TextInput id="ri_country" v-model="form.country" :maxlength="2" /></Field>
                <label v-if="!hasSbc" class="flex items-center gap-2 text-ui"><input v-model="form.is_state_reinsurer" type="checkbox" /> State reinsurer (Sadharan Bima Corporation): takes the compulsory share</label>
            </FormLayout>
        </Drawer>
    </AppLayout>
</template>
