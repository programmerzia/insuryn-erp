<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormError from '@/components/FormError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const useRecoveryCode = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function submit(): void {
    form.transform((data) => (useRecoveryCode.value ? { recovery_code: data.recovery_code } : { code: data.code })).post('/two-factor-challenge');
}
</script>

<template>
    <AuthLayout
        title="Two-factor authentication"
        :description="useRecoveryCode ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.'"
    >
        <form class="grid gap-4" @submit.prevent="submit">
            <div v-if="!useRecoveryCode" class="grid gap-1.5">
                <Label for="code">Code</Label>
                <Input id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" autofocus />
                <FormError :message="form.errors.code" />
            </div>
            <div v-else class="grid gap-1.5">
                <Label for="recovery_code">Recovery code</Label>
                <Input id="recovery_code" v-model="form.recovery_code" autocomplete="one-time-code" />
                <FormError :message="form.errors.recovery_code" />
            </div>
            <Button type="submit" :disabled="form.processing">Continue</Button>
            <Button variant="ghost" @click="useRecoveryCode = !useRecoveryCode">
                {{ useRecoveryCode ? 'Use an authenticator code' : 'Use a recovery code' }}
            </Button>
        </form>
    </AuthLayout>
</template>
