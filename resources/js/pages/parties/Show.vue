<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { AREAS, usePermissions } from '@/lib/permissions';

const props = defineProps<{
    party: { id: string; kind: string; display_name: string; tax_id: string | null; roles: string[] };
    bankAccounts: { id: string; bank_name: string; account_no_masked: string; is_default: boolean }[];
    policies: { id: string; number: string | null; status: string; inception: string; expiry: string }[];
}>();

const form = useForm({ bank_name: '', account_number: '', is_default: false });
const { can } = usePermissions();
</script>

<template>
    <AppLayout :title="party.display_name">
        <PageHeader :eyebrow="`${party.kind} · ${party.roles.join(', ')}`" :title="party.display_name" :description="party.tax_id ? `Tax ID ${party.tax_id}` : undefined">
            <Link href="/parties" class="text-ui text-accent-text hover:underline">All parties</Link>
        </PageHeader>
        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <h2 class="text-section font-semibold">Bank accounts</h2>
                <ul class="mt-3 grid gap-2 text-ui">
                    <li v-for="account in bankAccounts" :key="account.id" class="flex justify-between">
                        <span>{{ account.bank_name }} <span class=" text-ink-2">{{ account.account_no_masked }}</span></span>
                        <span v-if="account.is_default" class="text-dense text-ok">default</span>
                    </li>
                    <li v-if="bankAccounts.length === 0" class="text-ink-2">No bank accounts.</li>
                </ul>
                <FormBanner />
                <!-- GA-07: only people who maintain customers add their bank accounts. -->
                <form v-if="can('party.manage')" class="mt-4 grid gap-3" @submit.prevent="form.post(`/parties/${props.party.id}/bank-accounts`, { onSuccess: () => form.reset() })">
                    <Field id="bank_name" label="Bank" :error="form.errors.bank_name"><Input id="bank_name" v-model="form.bank_name" /></Field>
                    <Field id="account_number" label="Account number" :error="form.errors.account_number"><Input id="account_number" v-model="form.account_number" /></Field>
                    <label class="flex items-center gap-2 text-ui text-ink-2"><input v-model="form.is_default" type="checkbox" class="size-4 accent-brick" /> Default account</label>
                    <Button type="submit" :disabled="form.processing" class="justify-self-start">Add bank account</Button>
                </form>
            </Card>
            <Card>
                <h2 class="text-section font-semibold">Policies held</h2>
                <ul class="mt-3 grid gap-2 text-ui">
                    <li v-for="policy in policies" :key="policy.id" class="flex items-center justify-between">
                        <Link v-if="can(...AREAS.policies)" :href="`/policies/${policy.id}`" class="text-accent-text hover:underline">{{ policy.number ?? 'Quote' }}</Link>
                        <span v-else>{{ policy.number ?? 'Quote' }}</span>
                        <span class="text-ink-2">{{ formatDate(policy.inception) }} – {{ formatDate(policy.expiry) }}</span>
                        <StatusBadge :status="policy.status" />
                    </li>
                    <li v-if="policies.length === 0" class="text-ink-2">No policies.</li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
