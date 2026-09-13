<script setup lang="ts">
import { Check } from 'lucide-vue-next';

/**
 * Brief §4 long flows (claim registration, policy issue): steps across the top, the current step's fields, and a persistent summary rail on
 * the right. Steps are a real sequence, so they are numbered. The parent validates before moving on. `free` steppers (the setup wizard, whose
 * steps save on their own) let any step open and tick the ones marked `done`.
 */
defineProps<{ steps: { id: string; label: string; done?: boolean }[]; current: number; free?: boolean; wide?: boolean }>();
const emit = defineEmits<{ go: [index: number] }>();
</script>

<template>
    <div class="grid gap-6" :class="wide ? 'xl:grid-cols-[minmax(0,880px)_280px]' : 'lg:grid-cols-[minmax(0,560px)_280px]'">
        <div class="min-w-0">
            <ol class="mb-5 flex flex-wrap items-center gap-x-2 gap-y-1 text-ui" aria-label="Steps">
                <li v-for="(step, index) in steps" :key="step.id" class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-control py-1 pr-2"
                        :class="index === current ? 'font-medium text-ink' : index < current || free ? 'text-ink-2 hover:text-ink' : 'cursor-default text-ink-2'"
                        :disabled="!free && index > current"
                        :aria-current="index === current ? 'step' : undefined"
                        @click="(free || index < current) && index !== current && emit('go', index)"
                    >
                        <span class="num inline-flex size-5 items-center justify-center rounded-full border text-dense" :class="index === current ? 'border-accent bg-accent text-accent-ink' : 'border-line-control'">
                            <Check v-if="free ? step.done : index < current" :size="12" :stroke-width="2" aria-hidden="true" /><template v-else>{{ index + 1 }}</template>
                        </span>
                        {{ step.label }}
                    </button>
                    <span v-if="index < steps.length - 1" class="h-px w-6 bg-line" aria-hidden="true" />
                </li>
            </ol>
            <slot />
        </div>
        <aside class="h-fit rounded-panel border border-line bg-surface-2 p-4 lg:sticky lg:top-0" aria-label="Summary">
            <h2 class="mb-2 text-ui font-medium">Summary</h2>
            <slot name="summary" />
        </aside>
    </div>
</template>
