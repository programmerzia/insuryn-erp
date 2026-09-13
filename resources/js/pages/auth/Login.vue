<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import FormError from '@/components/FormError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

defineProps<{ canResetPassword: boolean }>();

const form = useForm({ email: '', password: '', remember: false });

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
                    <Link v-if="canResetPassword" href="/forgot-password" class="text-xs text-blueprint hover:underline">Forgot password?</Link>
                </div>
                <Input id="password" v-model="form.password" type="password" autocomplete="current-password" required />
                <FormError :message="form.errors.password" />
            </div>
            <label class="flex items-center gap-2 text-sm text-ivory-dim" for="remember">
                <input id="remember" v-model="form.remember" type="checkbox" class="size-4 accent-brick" />
                Keep me signed in
            </label>
            <Button type="submit" :disabled="form.processing">Sign in</Button>
        </form>
    </AuthLayout>
</template>
