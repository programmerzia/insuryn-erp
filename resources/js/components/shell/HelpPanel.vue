<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { type HelpModule, type HelpText, loadHelp } from '@/lib/help';
import { savePreference, usePreferences } from '@/lib/preferences';

/**
 * Session S3 "How this works" (market cross-check G9): a collapsible panel on the right of a module's screen with a few plain sentences — what the
 * screen is for, what happens in the accounting, the next step — in English or Bangla. The words come from resources/help; open/closed and the
 * language are remembered per user. GA-30: the panel's language switch changes only the panel (`help_locale`); the whole interface's language is
 * chosen in the user menu (`locale`).
 */
const props = defineProps<{ module: HelpModule }>();
const preferences = usePreferences();
const panelLocale = computed(() => preferences.help_locale ?? preferences.locale);
const text = ref<HelpText | null>(null);
const failed = ref(false);

watch(() => [props.module, panelLocale.value] as const, async ([module, locale]) => {
    failed.value = false;
    try {
        text.value = await loadHelp(module, locale);
    } catch {
        failed.value = true;
    }
}, { immediate: true });
</script>

<template>
    <aside id="help-panel" class="flex w-[320px] min-h-0 flex-col border-l border-line bg-surface-2" aria-labelledby="help-panel-title" data-tour="help-panel">
        <div class="flex h-10 items-center gap-2 border-b border-line px-3">
            <h2 id="help-panel-title" class="text-ui font-semibold">How this works</h2>
            <div class="ml-auto flex rounded-control border border-line-control p-0.5 text-dense" role="group" aria-label="Language">
                <button type="button" class="rounded-control px-1.5 py-0.5" :class="panelLocale === 'en' ? 'bg-surface font-medium text-ink' : 'text-ink-2 hover:text-ink'" :aria-pressed="panelLocale === 'en'" @click="savePreference('help_locale', 'en' === preferences.locale ? null : 'en', 0)">English</button>
                <button type="button" class="rounded-control px-1.5 py-0.5" lang="bn" :class="panelLocale === 'bn' ? 'bg-surface font-medium text-ink' : 'text-ink-2 hover:text-ink'" :aria-pressed="panelLocale === 'bn'" @click="savePreference('help_locale', 'bn' === preferences.locale ? null : 'bn', 0)">বাংলা</button>
            </div>
            <button type="button" class="inline-flex size-7 items-center justify-center rounded-control text-ink-2 hover:bg-surface hover:text-ink" aria-label="Close How this works" @click="savePreference('help_open', false, 0)">
                <X :size="16" :stroke-width="1.5" />
            </button>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3" :lang="text?.locale ?? panelLocale" aria-live="polite">
            <p v-if="failed" class="text-ui text-ink-2">The explanation could not be loaded. Check your connection and open the panel again.</p>
            <template v-else-if="text">
                <h3 class="text-section font-semibold">{{ text.title }}</h3>
                <!-- Server-rendered from resources/help with raw HTML escaped (App\Http\Help\HelpContent). -->
                <div class="help-prose" v-html="text.html" />
            </template>
        </div>
    </aside>
</template>

<style scoped>
.help-prose :deep(h2) {
    margin-top: 1rem;
    margin-bottom: 0.25rem;
    font-size: var(--text-ui);
    font-weight: 600;
    color: var(--color-ink);
}
.help-prose :deep(p) {
    font-size: var(--text-body);
    line-height: 1.6;
    color: var(--color-ink);
}
.help-prose :deep(em) {
    font-style: normal;
    font-weight: 500;
}
</style>
