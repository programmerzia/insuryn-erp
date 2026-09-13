<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import Logo from '@/components/Logo.vue';
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
    <div class="min-h-screen bg-surface">
        <header class="border-b border-line bg-surface-2">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-8 gap-y-2 px-4 py-3 sm:px-6">
                <Link href="/accounting/journals" class="flex items-center gap-2 text-section font-semibold text-ink">
                    <Logo :size="20" />
                    Insuryn
                </Link>
                <nav class="flex flex-wrap gap-x-6 gap-y-1 text-ui" aria-label="Main">
                    <div v-for="group in groups" :key="group.label" class="flex items-center gap-4">
                        <span class="text-dense font-semibold text-ink-2/70">{{ group.label }}</span>
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="text-ink-2 hover:text-ink"
                            :class="{ 'text-ink underline decoration-accent-text underline-offset-8': page.url.startsWith(item.href) }"
                        >
                            {{ item.label }}
                        </Link>
                    </div>
                </nav>
                <div class="ml-auto flex items-center gap-4 text-ui">
                    <span v-if="tenant" class="text-ui font-medium text-ink-2" title="Organisation">{{ tenant.name }}</span>
                    <Link v-if="user" href="/approvals" class="text-ink-2 hover:text-ink">Approvals</Link>
                    <Link v-if="user" href="/account/security" class="text-ink-2 hover:text-ink" :title="`${user.email} · security settings`">{{ user.name }}</Link>
                    <Link
                        v-if="user"
                        href="/logout"
                        method="post"
                        as="button"
                        class="rounded-control px-2 py-1 text-ink-2 hover:bg-surface-2 hover:text-ink"
                    >
                        Sign out
                    </Link>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <p v-if="status" class="mb-4 rounded-control border border-ok/40 bg-ok/10 px-3 py-2 text-ui text-ok" role="status">{{ status }}</p>
            <slot />
        </main>
    </div>
</template>
