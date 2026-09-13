<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormError from '@/components/FormError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const props = defineProps<{ token: string; email: string }>();

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>

<template>
    <AuthLayout title="Choose a new password">
        <form class="grid gap-4" @submit.prevent="form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })">
            <div class="grid gap-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" required />
                <FormError :message="form.errors.email" />
            </div>
            <div class="grid gap-1.5">
                <Label for="password">New password</Label>
                <Input id="password" v-model="form.password" type="password" autocomplete="new-password" required />
                <FormError :message="form.errors.password" />
            </div>
            <div class="grid gap-1.5">
                <Label for="password_confirmation">Confirm new password</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <Button type="submit" :disabled="form.processing">Save password</Button>
        </form>
    </AuthLayout>
</template>
