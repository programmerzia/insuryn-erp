<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui';
import { computed, onMounted } from 'vue';
import { activeItem, visibleNavigation } from '@/lib/navigation';
import { navOpen, usePhone } from '@/lib/phone';
import { usePreferences } from '@/lib/preferences';
import { warmPages } from '@/lib/warmup';
import type { SharedProps } from '@/types/shared';

/**
 * Brief §3: collapsible to icons (Ctrl+B), badge = items needing action for this user, order = frequency of use. GA-16: on a phone (below 640 px) it is
 * hidden until the top bar's ☰ opens it over the page, always with its labels; following a link closes it.
 */
const page = usePage<SharedProps>();
const preferences = usePreferences();
const phone = usePhone();
const collapsed = computed(() => preferences.sidebar_collapsed && !phone.value);
const items = computed(() => visibleNavigation(page.props.auth.permissions ?? []));
const primary = computed(() => items.value.filter((item) => !item.secondary && !item.group));
// Design addendum v2 §B.6–B.8: a named group (Assets & budgets) between the daily work and the secondary items.
const grouped = computed(() => items.value.filter((item) => !item.secondary && item.group));
const secondary = computed(() => items.value.filter((item) => item.secondary));
const active = computed(() => activeItem(items.value, page.url));
const badges = computed(() => ({ ...(page.props.shell?.badges ?? {}), approvals: page.props.shell?.approvals ?? 0 }) as Record<string, number>);
onMounted(() => warmPages([...new Set(items.value.flatMap((item) => [item.page, item.detail].filter((p): p is string => !!p)))]));
const count = (badge?: string) => (badge ? (badges.value[badge] ?? 0) : 0);
</script>

<template>
    <nav
        id="main-navigation"
        class="flex min-h-0 flex-col overflow-y-auto border-r border-line bg-surface-2 py-2"
        :class="[collapsed ? 'w-12' : 'w-52', navOpen ? 'max-sm:fixed max-sm:top-(--topbar-h) max-sm:bottom-0 max-sm:left-0 max-sm:z-40 max-sm:w-64 max-sm:shadow-float' : 'max-sm:hidden']"
        aria-label="Main"
    >
        <TooltipProvider :delay-duration="300">
            <template v-for="(group, groupIndex) in [primary, grouped, secondary]" :key="groupIndex">
                <hr v-if="groupIndex > 0 && group.length" class="mx-3 my-2 border-line" />
                <p v-if="groupIndex === 1 && group.length && !collapsed" class="mx-3.5 mb-1 text-dense font-medium text-ink-2">{{ group[0]?.group }}</p>
                <TooltipRoot v-for="item in group" :key="item.id" :disabled="!collapsed">
                    <TooltipTrigger as-child>
                        <Link
                            :href="item.href"
                            prefetch="hover"
                            :cache-for="['30s', '1m']"
                            class="mx-1.5 flex h-8 items-center gap-2.5 rounded-control px-2 text-ui text-ink-2 hover:bg-surface hover:text-ink"
                            :class="{ 'bg-accent-soft font-medium text-ink hover:bg-accent-soft': active?.id === item.id, 'justify-center': collapsed }"
                            :aria-current="active?.id === item.id ? 'page' : undefined"
                            :aria-label="collapsed ? `${item.label}${count(item.badge) ? `, ${count(item.badge)} need action` : ''}` : undefined"
                            @click="navOpen = false"
                        >
                            <span class="relative inline-flex">
                                <component :is="item.icon" :size="16" :stroke-width="1.5" aria-hidden="true" />
                                <span v-if="collapsed && count(item.badge)" class="absolute -top-0.5 -right-1 size-1.5 rounded-full bg-accent" aria-hidden="true" />
                            </span>
                            <template v-if="!collapsed">
                                <span class="truncate">{{ item.label }}</span>
                                <span v-if="count(item.badge)" class="num ml-auto inline-flex items-center gap-1 text-dense text-ink-2">
                                    <span class="size-1.5 rounded-full bg-accent" aria-hidden="true" />{{ count(item.badge) }}<span class="sr-only"> need action</span>
                                </span>
                            </template>
                        </Link>
                    </TooltipTrigger>
                    <TooltipPortal>
                        <TooltipContent side="right" :side-offset="6" class="z-50 rounded-control border border-line bg-surface px-2 py-1 text-dense text-ink shadow-float">
                            {{ item.label }}<template v-if="count(item.badge)"> · {{ count(item.badge) }}</template>
                        </TooltipContent>
                    </TooltipPortal>
                </TooltipRoot>
            </template>
        </TooltipProvider>
    </nav>
</template>
