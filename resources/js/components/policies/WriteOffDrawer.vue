<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/forms/Field.vue';
import FormLayout from '@/components/forms/FormLayout.vue';
import TextInput from '@/components/forms/TextInput.vue';
import Drawer from '@/components/ui/Drawer.vue';
import { formatMoney } from '@/lib/format';

/** Gap fixes W7 (GA-24): "Write off small balance" on a cancelled policy. The request goes for approval; nothing posts until finance approves it. */
export interface WriteOffOutlook {
    owed: string;
    limit: string;
    within_limit: boolean;
    pending: { requested: string; requested_at: string } | null;
}
const props = defineProps<{ policyId: string; title: string; currency: string; outlook: WriteOffOutlook }>();
const open = defineModel<boolean>('open', { default: false });
const form = useForm({ reason: '' });

function submit(): void {
    form.post(`/policies/${props.policyId}/write-off`, { preserveScroll: true, onSuccess: () => { form.reset(); open.value = false; } });
}
</script>

<template>
    <Drawer v-model:open="open" :title="`Write off the balance of ${title}`">
        <FormLayout submit-label="Send for approval" :dirty="form.isDirty" :processing="form.processing" :error="(form.errors as Record<string, string>).form" @submit="submit" @cancel="open = false">
            <p class="text-ui text-ink-2">
                The customer still owes {{ formatMoney(outlook.owed) }} {{ currency }} of premium earned before the cancellation. Writing it off takes it off their bill and
                charges it to Premium Written Off. Someone in finance approves it and sees the entries first; nothing is posted until then.
            </p>
            <Field id="write_off_reason" label="Why it is not collected" hint="Like: customer unreachable, cost of collecting is more than the amount." :error="form.errors.reason">
                <TextInput v-model="form.reason" />
            </Field>
        </FormLayout>
    </Drawer>
</template>
