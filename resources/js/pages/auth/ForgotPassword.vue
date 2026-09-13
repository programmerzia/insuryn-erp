<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import FormError from '@/components/FormError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const form = useForm({ email: '' });
</script>

<template>
    <AuthLayout title="Reset password" description="We will email you a link to choose a new password.">
        <form class="grid gap-4" @submit.prevent="form.post('/forgot-password')">
            <div class="grid gap-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" required autofocus />
                <FormError :message="form.errors.email" />
            </div>
            <Button type="submit" :disabled="form.processing">Email reset link</Button>
            <Link href="/login" class="text-center text-sm text-blueprint hover:underline">Back to sign in</Link>
        </form>
    </AuthLayout>
</template>
