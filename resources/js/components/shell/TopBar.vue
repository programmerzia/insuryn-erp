<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, ChevronDown, CircleUser, LogOut, PanelLeft, Search, Settings, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';
import Logo from '@/components/Logo.vue';
import Kbd from '@/components/ui/Kbd.vue';
import { Menu, MenuContent, MenuItem, MenuLabel, MenuRadioGroup, MenuRadioItem, MenuSeparator, MenuTrigger } from '@/components/ui/menu';
import { openPalette } from '@/lib/palette';
import { savePreference, usePreferences } from '@/lib/preferences';
import { shortcutKeys } from '@/lib/shortcuts';
import type { SharedProps } from '@/types/shared';

/** Brief §3 top bar (44px): sidebar toggle, entity/branch switcher, search + command field, notifications, settings, user. */
const page = usePage<SharedProps>();
const preferences = usePreferences();
const shell = computed(() => page.props.shell);
const user = computed(() => page.props.auth.user);
const branchLabel = computed(() => shell.value?.branches.find((b) => b.id === preferences.branch_id)?.name ?? 'All branches');
const branchValue = computed({ get: () => preferences.branch_id ?? 'all', set: (value: string) => savePreference('branch_id', value === 'all' ? null : value, 0) });
const theme = computed({ get: () => preferences.theme, set: (value: string) => savePreference('theme', value, 0) });
const density = computed({ get: () => preferences.density, set: (value: string) => savePreference('density', value, 0) });
const toggleSidebar = () => savePreference('sidebar_collapsed', !preferences.sidebar_collapsed);
const signOut = () => router.post('/logout');
</script>

<template>
    <header class="flex h-(--topbar-h) items-center gap-2 border-b border-line bg-surface px-2">
        <button type="button" class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" :title="`Collapse or expand the sidebar (${shortcutKeys('app.sidebar')})`" aria-label="Collapse or expand the sidebar" @click="toggleSidebar">
            <PanelLeft :size="16" :stroke-width="1.5" />
        </button>
        <Link href="/home" class="flex items-center gap-2 pr-2 text-ui font-semibold text-ink" aria-label="Insuryn home"><Logo :size="18" /><span class="max-lg:sr-only">Insuryn</span></Link>

        <Menu v-if="shell?.entity">
            <MenuTrigger class="flex h-8 items-center gap-1.5 rounded-control px-2 text-ui text-ink hover:bg-surface-2">
                <span class="font-medium">{{ shell.entity.name }}</span>
                <span class="text-ink-2 max-md:hidden">· {{ branchLabel }}</span>
                <ChevronDown :size="14" :stroke-width="1.5" class="text-ink-2" />
            </MenuTrigger>
            <MenuContent align="start" width="w-64">
                <MenuLabel>Entity</MenuLabel>
                <div class="flex h-8 items-center px-2 text-ui">{{ shell.entity.name }} <span class="ml-auto text-ink-2">{{ shell.entity.currency }}</span></div>
                <MenuSeparator class="my-1 h-px bg-line" />
                <MenuLabel>Branch</MenuLabel>
                <MenuRadioGroup v-model="branchValue">
                    <MenuRadioItem value="all">All branches</MenuRadioItem>
                    <MenuRadioItem v-for="branch in shell.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</MenuRadioItem>
                </MenuRadioGroup>
            </MenuContent>
        </Menu>

        <button
            type="button"
            class="mx-auto flex h-8 w-full max-w-md items-center gap-2 rounded-control border border-line-control bg-surface px-2 text-ui text-ink-2 hover:border-ink-2"
            @click="openPalette"
        >
            <Search :size="16" :stroke-width="1.5" aria-hidden="true" />
            <span class="truncate">Search or run a command</span>
            <Kbd class="ml-auto" :keys="shortcutKeys('app.palette')" />
        </button>

        <Link href="/approvals" class="relative inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" :aria-label="`Approvals waiting for you: ${shell?.approvals ?? 0}`" :title="`Approvals waiting for you: ${shell?.approvals ?? 0}`">
            <Bell :size="16" :stroke-width="1.5" />
            <span v-if="shell?.approvals" class="absolute top-1.5 right-1.5 size-1.5 rounded-full bg-accent" aria-hidden="true" />
        </Link>

        <Menu>
            <MenuTrigger class="inline-flex size-8 items-center justify-center rounded-control text-ink-2 hover:bg-surface-2 hover:text-ink" aria-label="Display settings" title="Display settings">
                <Settings :size="16" :stroke-width="1.5" />
            </MenuTrigger>
            <MenuContent>
                <MenuLabel>Theme</MenuLabel>
                <MenuRadioGroup v-model="theme">
                    <MenuRadioItem value="system">Match the system</MenuRadioItem>
                    <MenuRadioItem value="light">Light</MenuRadioItem>
                    <MenuRadioItem value="dark">Dark</MenuRadioItem>
                </MenuRadioGroup>
                <MenuSeparator class="my-1 h-px bg-line" />
                <MenuLabel>Row density</MenuLabel>
                <MenuRadioGroup v-model="density">
                    <MenuRadioItem value="compact">Compact (32px rows)</MenuRadioItem>
                    <MenuRadioItem value="comfortable">Comfortable (40px rows)</MenuRadioItem>
                </MenuRadioGroup>
                <MenuSeparator class="my-1 h-px bg-line" />
                <MenuItem shortcut="app.sidebar" @select="toggleSidebar">{{ preferences.sidebar_collapsed ? 'Expand' : 'Collapse' }} the sidebar</MenuItem>
            </MenuContent>
        </Menu>

        <Menu v-if="user">
            <MenuTrigger class="flex h-8 items-center gap-1.5 rounded-control px-2 text-ui text-ink hover:bg-surface-2">
                <CircleUser :size="16" :stroke-width="1.5" class="text-ink-2" />
                <span class="max-md:sr-only">{{ user.name }}</span>
                <ChevronDown :size="14" :stroke-width="1.5" class="text-ink-2" />
            </MenuTrigger>
            <MenuContent>
                <MenuLabel>{{ user.email }}</MenuLabel>
                <MenuItem @select="router.visit('/account/security')"><ShieldCheck :size="16" :stroke-width="1.5" />Security settings</MenuItem>
                <MenuSeparator class="my-1 h-px bg-line" />
                <MenuItem @select="signOut"><LogOut :size="16" :stroke-width="1.5" />Sign out</MenuItem>
            </MenuContent>
        </Menu>
    </header>
</template>
