<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui';
import { computed, onMounted } from 'vue';
import { activeItem, type NavItem, type NavSection, sections, visibleNavigation } from '@/lib/navigation';
import { navOpen, usePhone } from '@/lib/phone';
import { savePreference, usePreferences } from '@/lib/preferences';
import { warmPages } from '@/lib/warmup';
import type { SharedProps } from '@/types/shared';

/**
 * Brief §3: collapsible to icons (Ctrl+B), badge = items needing action for this user. Items are grouped in the sections of `navigation.ts`; each heading
 * is a button that opens or closes its section (saved per user as `sidebar_sections.<id>`); the section holding the current page is always open, and a
 * section with a badge count opens for a first-time user. In the icon-only sidebar a thin divider stands in for each heading. GA-16: on a phone (below 640 px)
 * it is hidden until the top bar's ☰ opens it over the page, always with its labels; following a link closes it.
 */
const page = usePage<SharedProps>();
const preferences = usePreferences();
const phone = usePhone();
const collapsed = computed(() => preferences.sidebar_collapsed && !phone.value);
const items = computed(() => visibleNavigation(page.props.auth.permissions ?? []));
const active = computed(() => activeItem(items.value, page.url));
const badges = computed(() => ({ ...(page.props.shell?.badges ?? {}), approvals: page.props.shell?.approvals ?? 0 }) as Record<string, number>);
onMounted(() => warmPages([...new Set(items.value.flatMap((item) => [item.page, item.detail].filter((p): p is string => !!p)))]));
const count = (badge?: string) => (badge ? (badges.value[badge] ?? 0) : 0);

interface Group {
    section: NavSection;
    items: NavItem[];
    open: boolean;
}
/** Sections with at least one page open to the user; `open` = the user's choice, else the default (or a badge to act on), and always when the page is here. */
const groups = computed<Group[]>(() => sections
    .map((section) => ({ section, items: items.value.filter((item) => item.section === section.id) }))
    .filter((group) => group.items.length > 0)
    .map((group) => ({ ...group, open: isOpen(group) })));
function isOpen(group: { section: NavSection; items: NavItem[] }): boolean {
    if (group.section.id === 'home' || group.items.some((item) => item.id === active.value?.id)) return true;
    const chosen = preferences.sidebar_sections?.[group.section.id];
    if (typeof chosen === 'boolean') return chosen;
    return group.section.open || group.items.some((item) => count(item.badge) > 0);
}
const toggle = (group: Group) => savePreference(`sidebar_sections.${group.section.id}`, !group.open);
/** A label that does not fit (a narrow phone drawer, a long translation) shows in full as the browser's tooltip. */
function fullLabelWhenClipped(event: MouseEvent, label: string): void {
    const el = event.currentTarget as HTMLElement;
    el.title = el.scrollWidth > el.clientWidth ? label : '';
}
</script>

<template>
    <nav
        id="main-navigation"
        class="flex min-h-0 flex-col overflow-y-auto border-r border-line bg-surface-2 py-2"
        :class="[collapsed ? 'w-12' : 'w-56', navOpen ? 'max-sm:fixed max-sm:top-(--topbar-h) max-sm:bottom-0 max-sm:left-0 max-sm:z-40 max-sm:w-64 max-sm:shadow-float' : 'max-sm:hidden']"
        aria-label="Main"
    >
        <TooltipProvider :delay-duration="300">
            <!-- data-hrefs lets the browser flows (scripts/flow-audit.mjs, tests/e2e) open the section a link lives in before clicking it. -->
            <section v-for="(group, index) in groups" :key="group.section.id" :data-section="group.section.id" :data-hrefs="group.items.map((item) => item.href).join(' ')">
                <hr v-if="index > 0 && collapsed" class="mx-3 my-1.5 border-line" />
                <button
                    v-else-if="index > 0"
                    type="button"
                    class="mx-1.5 mt-2 flex h-7 w-[calc(100%-0.75rem)] items-center gap-1 rounded-control px-2 text-dense font-medium tracking-wider text-ink-2 uppercase hover:bg-surface hover:text-ink"
                    :aria-expanded="group.open"
                    :aria-controls="`nav-section-${group.section.id}`"
                    @click="toggle(group)"
                >
                    <ChevronRight :size="14" :stroke-width="1.5" aria-hidden="true" class="shrink-0 transition-transform" :class="{ 'rotate-90': group.open }" />
                    <span class="truncate">{{ group.section.label }}</span>
                </button>
                <div v-if="group.open || collapsed" :id="`nav-section-${group.section.id}`" class="flex flex-col">
                    <TooltipRoot v-for="item in group.items" :key="item.id" :disabled="!collapsed">
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
                                <span class="relative inline-flex shrink-0">
                                    <component :is="item.icon" :size="16" :stroke-width="1.5" aria-hidden="true" />
                                    <span v-if="collapsed && count(item.badge)" class="absolute -top-0.5 -right-1 size-1.5 rounded-full bg-accent" aria-hidden="true" />
                                </span>
                                <template v-if="!collapsed">
                                    <span class="min-w-0 truncate" @mouseenter="fullLabelWhenClipped($event, item.label)">{{ item.label }}</span>
                                    <span v-if="count(item.badge)" class="num ml-auto inline-flex shrink-0 items-center gap-1 text-dense text-ink-2">
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
                </div>
            </section>
        </TooltipProvider>
    </nav>
</template>
