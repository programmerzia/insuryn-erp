<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { accessRequestHref } from '@/lib/errorPage';
import type { SharedProps } from '@/types/shared';

/**
 * Gap fix GA-07: refused (403), missing (404), expired (419) and failed (500) pages stay in the application — inside the shell for a signed-in user —
 * with what happened, Back, and for a refusal who can give access (UX brief §4: errors say what happened and what to do).
 */
const props = defineProps<{
    status: number;
    title: string;
    message: string;
    permissions: { code: string; label: string; help: string | null }[];
    access: { admins: { name: string; email: string }[]; roles: string[]; subject: string } | null;
    back: string | null;
}>();

const page = usePage<SharedProps>();
const signedIn = computed(() => Boolean(page.props.auth?.user && page.props.shell));
const ask = computed(() => (props.access ? accessRequestHref(props.access, typeof window === 'undefined' ? '' : window.location.href) : null));

function goBack(): void {
    if (props.back) window.location.assign(props.back);
    else window.history.back();
}
</script>

<template>
    <component :is="signedIn ? AppLayout : 'div'" v-bind="signedIn ? { title } : { class: 'flex min-h-screen items-center justify-center bg-surface px-4 py-10 text-ink' }">
        <Head v-if="!signedIn" :title="title" />
        <section class="max-w-[640px]" aria-labelledby="error-title" :data-status="status">
            <p class="text-dense text-ink-2 tabular-nums">Error {{ status }}</p>
            <h1 id="error-title" class="text-title font-semibold">{{ title }}</h1>
            <p class="mt-2 text-ui text-ink-2">{{ message }}</p>
            <p v-if="permissions.length === 1" class="mt-3 text-ui">
                It needs <strong class="font-medium">{{ permissions[0]!.label }}</strong><template v-if="permissions[0]!.help">: {{ permissions[0]!.help }}</template>
            </p>
            <p v-else-if="permissions.length > 1" class="mt-3 text-ui">It opens with any of: {{ permissions.map((p) => p.label).join(', ') }}.</p>
            <p v-if="access && access.roles.length" class="mt-2 text-ui text-ink-2">Roles that give it: {{ access.roles.join(', ') }}.</p>
            <p v-if="access && access.admins.length" class="mt-2 text-ui text-ink-2">
                {{ access.admins.length === 1 ? 'Your administrator' : 'Your administrators' }}, {{ access.admins.map((a) => a.name).join(', ') }}, can give you access.
            </p>
            <div class="mt-5 flex flex-wrap gap-2">
                <button type="button" class="inline-flex h-8 items-center rounded-control border border-line-control px-3 text-ui hover:bg-surface-2" @click="goBack">Back</button>
                <a v-if="ask" :href="ask" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover">Ask for access</a>
                <Link v-if="signedIn" href="/home" class="inline-flex h-8 items-center rounded-control px-3 text-ui text-accent-text hover:bg-surface-2">Go to Home</Link>
                <a v-else href="/login" class="inline-flex h-8 items-center rounded-control px-3 text-ui text-accent-text hover:bg-surface-2">Sign in</a>
            </div>
        </section>
    </component>
</template>
