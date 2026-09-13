<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormError from '@/components/FormError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const form = useForm({ password: '' });
</script>

<template>
    <AuthLayout title="Confirm your password" description="This is a protected action. Enter your password to continue.">
        <form class="grid gap-4" @submit.prevent="form.post('/user/confirm-password', { onFinish: () => form.reset() })">
            <div class="grid gap-1.5">
                <Label for="password">Password</Label>
                <Input id="password" v-model="form.password" type="password" autocomplete="current-password" required autofocus />
                <FormError :message="form.errors.password" />
            </div>
            <Button type="submit" :disabled="form.processing">Confirm</Button>
        </form>
    </AuthLayout>
</template>
