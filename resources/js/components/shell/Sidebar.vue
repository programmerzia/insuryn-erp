<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui';
import { computed } from 'vue';
import { activeItem, visibleNavigation } from '@/lib/navigation';
import { usePreferences } from '@/lib/preferences';
import type { SharedProps } from '@/types/shared';

/** Brief §3: collapsible to icons (Ctrl+B), badge = items needing action for this user, order = frequency of use. */
const page = usePage<SharedProps>();
const preferences = usePreferences();
const items = computed(() => visibleNavigation(page.props.auth.permissions ?? []));
const primary = computed(() => items.value.filter((item) => !item.secondary));
const secondary = computed(() => items.value.filter((item) => item.secondary));
const active = computed(() => activeItem(items.value, page.url));
const badges = computed(() => ({ ...(page.props.shell?.badges ?? {}), approvals: page.props.shell?.approvals ?? 0 }) as Record<string, number>);
const count = (badge?: string) => (badge ? (badges.value[badge] ?? 0) : 0);
</script>

<template>
    <nav class="flex min-h-0 flex-col overflow-y-auto border-r border-line bg-surface-2 py-2" :class="preferences.sidebar_collapsed ? 'w-12' : 'w-52'" aria-label="Main">
        <TooltipProvider :delay-duration="300">
            <template v-for="(group, groupIndex) in [primary, secondary]" :key="groupIndex">
                <hr v-if="groupIndex === 1 && group.length" class="mx-3 my-2 border-line" />
                <TooltipRoot v-for="item in group" :key="item.id" :disabled="!preferences.sidebar_collapsed">
                    <TooltipTrigger as-child>
                        <Link
                            :href="item.href"
                            class="mx-1.5 flex h-8 items-center gap-2.5 rounded-control px-2 text-ui text-ink-2 hover:bg-surface hover:text-ink"
                            :class="{ 'bg-accent-soft font-medium text-ink hover:bg-accent-soft': active?.id === item.id, 'justify-center': preferences.sidebar_collapsed }"
                            :aria-current="active?.id === item.id ? 'page' : undefined"
                            :aria-label="preferences.sidebar_collapsed ? `${item.label}${count(item.badge) ? `, ${count(item.badge)} need action` : ''}` : undefined"
                        >
                            <span class="relative inline-flex">
                                <component :is="item.icon" :size="16" :stroke-width="1.5" aria-hidden="true" />
                                <span v-if="preferences.sidebar_collapsed && count(item.badge)" class="absolute -top-0.5 -right-1 size-1.5 rounded-full bg-accent" aria-hidden="true" />
                            </span>
                            <template v-if="!preferences.sidebar_collapsed">
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
