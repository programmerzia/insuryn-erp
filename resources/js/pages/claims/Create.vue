<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/forms/Field.vue';
import FormBanner from '@/components/forms/FormBanner.vue';
import SelectInput from '@/components/forms/SelectInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{ policies: { id: string; number: string; display_name: string; inception: string; expiry: string }[] }>();
const form = useForm({ policy_id: '', loss_date: '', reported_on: '', description: '' });
</script>

<template>
    <AppLayout title="Register claim">
        <PageHeader eyebrow="Claims" title="Register claim" description="The loss must fall within the policy's cover." />
        <Card class="max-w-xl">
            <FormBanner />
            <form class="grid gap-4" @submit.prevent="form.post('/claims')">
                <Field id="policy_id" label="Policy" :error="form.errors.policy_id">
                    <SelectInput id="policy_id" v-model="form.policy_id" placeholder="Choose a policy" :options="policies.map((p) => ({ value: p.id, label: `${p.number} · ${p.display_name} · ${p.inception} – ${p.expiry}` }))" />
                </Field>
                <Field id="loss_date" label="Date of loss" :error="form.errors.loss_date"><Input id="loss_date" v-model="form.loss_date" type="date" /></Field>
                <Field id="reported_on" label="Reported on" :error="form.errors.reported_on"><Input id="reported_on" v-model="form.reported_on" type="date" /></Field>
                <Field id="description" label="What happened" :error="form.errors.description">
                    <textarea id="description" v-model="form.description" rows="4" class="rounded-md border border-line-control bg-surface px-3 py-2 text-sm text-ivory" />
                </Field>
                <Button type="submit" :disabled="form.processing" class="justify-self-start">Register claim</Button>
            </form>
        </Card>
    </AppLayout>
</template>
