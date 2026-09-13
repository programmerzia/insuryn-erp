<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { savePreference, usePreferences } from '@/lib/preferences';
import { loadTour, type TourMove, tourAction, tourSteps, type TourText } from '@/lib/tour';

/**
 * Session S4 guided tour (market cross-check Part A): a spotlight on one element of the step's page and a card with what to do and why, Back / Next,
 * and End tour. It never blocks the page — the user can do the step for real — and its state is a user preference, so ending it keeps the step
 * for resuming from Home. On another page, the card offers to go to the step's page.
 */
const page = usePage();
const preferences = usePreferences();
const texts = ref<TourText[]>([]);
const rect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
const card = ref<HTMLElement | null>(null);
const next = ref<HTMLButtonElement | null>(null);
const cardHeight = ref(240);
const cardObserver = new ResizeObserver(() => (cardHeight.value = card.value?.offsetHeight ?? cardHeight.value));
watch(card, (element, previous) => {
    if (previous) cardObserver.unobserve(previous);
    if (element) cardObserver.observe(element);
});

const state = computed(() => preferences.tour);
const active = computed(() => state.value?.status === 'active');
const index = computed(() => Math.min(state.value?.step ?? 0, tourSteps.length - 1));
const step = computed(() => tourSteps[index.value]!);
const text = computed(() => texts.value.find((t) => t.id === step.value.id));
const path = computed(() => page.url.split('?')[0]);
const here = computed(() => path.value === step.value.href);

watch(() => [active.value, preferences.locale] as const, async ([on, locale]) => {
    if (on) texts.value = await loadTour(locale).catch(() => []);
}, { immediate: true });

let target: Element | null = null;
let observer: ResizeObserver | null = null;
function measure(): void {
    if (!target) return;
    const r = target.getBoundingClientRect();
    rect.value = { top: r.top, left: r.left, width: r.width, height: r.height };
}
async function locate(): Promise<void> {
    observer?.disconnect();
    target = null;
    rect.value = null;
    if (!active.value || !here.value) return;
    await nextTick();
    for (let attempt = 0; attempt < 10 && !target; attempt++) {
        target = document.querySelector(`[data-tour="${step.value.target}"]`);
        if (!target) await new Promise((resolve) => setTimeout(resolve, 100));
    }
    target ??= document.getElementById('main');
    if (!target) return;
    target.scrollIntoView({ block: 'nearest' });
    measure();
    observer = new ResizeObserver(measure);
    observer.observe(target);
    await nextTick();
    next.value?.focus({ preventScroll: true });
}
watch(() => [active.value, index.value, path.value] as const, () => void locate(), { immediate: true });
onMounted(() => {
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, true);
});
onBeforeUnmount(() => {
    observer?.disconnect();
    cardObserver.disconnect();
    window.removeEventListener('resize', measure);
    window.removeEventListener('scroll', measure, true);
});

/** Card below the spotlight when there is room, otherwise above it, otherwise in the bottom-right corner (a spotlight as tall as the window). */
const cardStyle = computed(() => {
    const width = 360;
    const corner = { right: '16px', bottom: '40px', width: `${width}px` };
    if (!rect.value) return corner;
    const gap = 14;
    const below = rect.value.top + rect.value.height + gap;
    const above = rect.value.top - gap - cardHeight.value;
    const left = `${Math.min(Math.max(8, rect.value.left), window.innerWidth - width - 8)}px`;
    if (below + cardHeight.value < window.innerHeight - 32) return { top: `${below}px`, left, width: `${width}px` };
    if (above > 52) return { top: `${above}px`, left, width: `${width}px` };
    return corner;
});

function move(action: TourMove): void {
    const after = tourAction(state.value, action);
    savePreference('tour', after, 0);
    const destination = tourSteps[after.step]!.href;
    if (after.status === 'active' && destination !== path.value) router.visit(destination);
}
function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && active.value) move('dismiss');
}
</script>

<template>
    <template v-if="active">
        <div
            v-if="rect && here"
            class="pointer-events-none fixed z-40 rounded-panel ring-2 ring-accent transition-[top,left,width,height] duration-150 motion-reduce:transition-none"
            :style="{ top: `${rect.top - 6}px`, left: `${rect.left - 6}px`, width: `${rect.width + 12}px`, height: `${rect.height + 12}px`, boxShadow: '0 0 0 9999px var(--color-scrim)' }"
            aria-hidden="true"
        />
        <section
            ref="card"
            class="fixed z-50 grid gap-2 rounded-panel border border-line bg-surface p-4 text-ink shadow-float"
            :style="cardStyle"
            role="dialog"
            aria-modal="false"
            aria-labelledby="tour-title"
            :lang="preferences.locale"
            data-tour-card
            @keydown="onKey"
        >
            <p class="text-dense text-ink-2">Guided tour · step {{ index + 1 }} of {{ tourSteps.length }}</p>
            <h2 id="tour-title" class="text-section font-semibold">{{ text?.title ?? '…' }}</h2>
            <!-- Server-rendered from resources/help/tour.<locale>.md with raw HTML escaped (App\Http\Help\HelpContent::tour). -->
            <div v-if="here" class="tour-text text-body" v-html="text?.html ?? ''" />
            <p v-else class="text-body">This step happens on another page.</p>
            <div class="mt-1 flex items-center gap-2">
                <button v-if="index > 0 && here" type="button" class="inline-flex h-8 items-center rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="move('back')">Back</button>
                <button v-if="here" ref="next" type="button" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="move('next')">
                    {{ index === tourSteps.length - 1 ? 'Finish the tour' : 'Next' }}
                </button>
                <button v-else ref="next" type="button" class="inline-flex h-8 items-center rounded-control bg-accent px-3 text-ui font-medium text-accent-ink hover:bg-accent-hover" @click="router.visit(step.href)">Go to this step</button>
                <button type="button" class="ml-auto inline-flex h-8 items-center rounded-control px-3 text-ui text-ink-2 hover:bg-surface-2" @click="move('dismiss')">End tour</button>
            </div>
        </section>
    </template>
</template>

<style scoped>
.tour-text :deep(p) {
    line-height: 1.55;
}
.tour-text :deep(p + p) {
    margin-top: 0.5rem;
}
.tour-text :deep(em) {
    font-style: normal;
    font-weight: 500;
}
</style>
