<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { defineAsyncComponent, nextTick, ref, useTemplateRef } from 'vue';
import type { DemoAccount } from '@/components/auth/DemoAccountsDialog.vue';
import FormError from '@/components/FormError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

/** `demoAccounts` is sent only in the local environment (DemoAccounts::forSignIn). */
defineProps<{ canResetPassword: boolean; demoAccounts?: DemoAccount[] }>();

const DemoAccountsDialog = defineAsyncComponent(() => import('@/components/auth/DemoAccountsDialog.vue'));
const form = useForm({ email: '', password: '', remember: false });
const demoOpen = ref(false);
const signIn = useTemplateRef<{ $el: HTMLButtonElement }>('signIn');

async function useAccount(account: DemoAccount): Promise<void> {
    form.email = account.email;
    form.password = account.password;
    form.clearErrors();
    await nextTick();
    requestAnimationFrame(() => signIn.value?.$el.focus());
}

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AuthLayout title="Sign in" description="Use the email and password of your account in this organisation.">
        <form class="grid gap-4" @submit.prevent="submit">
            <div class="grid gap-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" required autofocus />
                <FormError :message="form.errors.email" />
            </div>
            <div class="grid gap-1.5">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <Link v-if="canResetPassword" href="/forgot-password" class="text-dense text-accent-text hover:underline">Forgot password?</Link>
                </div>
                <Input id="password" v-model="form.password" type="password" autocomplete="current-password" required />
                <FormError :message="form.errors.password" />
            </div>
            <label class="flex items-center gap-2 text-ui text-ink-2" for="remember">
                <input id="remember" v-model="form.remember" type="checkbox" class="size-4 accent-brick" />
                Keep me signed in
            </label>
            <Button ref="signIn" type="submit" :disabled="form.processing">Sign in</Button>
        </form>
        <div v-if="demoAccounts" class="mt-6 flex items-center justify-between gap-3 border-t border-line pt-4">
            <p class="text-dense text-ink-2">Local environment</p>
            <Button variant="secondary" size="sm" @click="demoOpen = true">Use a demo account</Button>
        </div>
        <DemoAccountsDialog v-if="demoAccounts" v-model:open="demoOpen" :accounts="demoAccounts" @choose="useAccount" />
    </AuthLayout>
</template>
