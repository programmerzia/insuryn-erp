<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types/shared';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const tenant = computed(() => page.props.tenant);

const nav = [
    { label: 'Journals', href: '/accounting/journals' },
    { label: 'Trial balance', href: '/accounting/trial-balance' },
    { label: 'Imports', href: '/accounting/imports' },
];
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-bg">
        <header class="border-b border-line bg-bg-deep">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-8 gap-y-2 px-4 py-3 sm:px-6">
                <Link href="/accounting/journals" class="flex items-center gap-2 font-display text-lg font-bold tracking-tight text-ivory">
                    <span class="inline-block size-2.5 bg-brick" aria-hidden="true" />
                    Insuryn
                </Link>
                <nav class="flex gap-5 text-sm" aria-label="Accounting">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="text-ivory-dim hover:text-ivory"
                        :class="{ 'text-ivory underline decoration-blueprint underline-offset-8': page.url.startsWith(item.href) }"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
                <div class="ml-auto flex items-center gap-4 text-sm">
                    <span v-if="tenant" class="text-xs font-semibold uppercase tracking-wider text-blueprint" title="Organisation">{{ tenant.name }}</span>
                    <span v-if="user" class="text-ivory-dim" :title="user.email">{{ user.name }}</span>
                    <Link
                        v-if="user"
                        href="/logout"
                        method="post"
                        as="button"
                        class="rounded-md px-2 py-1 text-ivory-dim hover:bg-surface-raised hover:text-ivory"
                    >
                        Sign out
                    </Link>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <slot />
        </main>
    </div>
</template>
