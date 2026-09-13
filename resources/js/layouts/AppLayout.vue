<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { visibleNavigation } from '@/lib/navigation';
import type { SharedProps } from '@/types/shared';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const tenant = computed(() => page.props.tenant);

const groups = computed(() => visibleNavigation(page.props.auth.permissions ?? []));
const status = computed(() => page.props.status);
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
                <nav class="flex flex-wrap gap-x-6 gap-y-1 text-sm" aria-label="Main">
                    <div v-for="group in groups" :key="group.label" class="flex items-center gap-4">
                        <span class="text-xs font-semibold tracking-wider text-ivory-dim/70 uppercase">{{ group.label }}</span>
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="text-ivory-dim hover:text-ivory"
                            :class="{ 'text-ivory underline decoration-blueprint underline-offset-8': page.url.startsWith(item.href) }"
                        >
                            {{ item.label }}
                        </Link>
                    </div>
                </nav>
                <div class="ml-auto flex items-center gap-4 text-sm">
                    <span v-if="tenant" class="text-xs font-semibold uppercase tracking-wider text-blueprint" title="Organisation">{{ tenant.name }}</span>
                    <Link v-if="user" href="/account/security" class="text-ivory-dim hover:text-ivory" :title="`${user.email} · security settings`">{{ user.name }}</Link>
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
            <p v-if="status" class="mb-4 rounded-md border border-green/40 bg-green/10 px-3 py-2 text-sm text-green" role="status">{{ status }}</p>
            <slot />
        </main>
    </div>
</template>
