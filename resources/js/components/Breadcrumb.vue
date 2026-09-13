<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { type Crumb, currentTrail } from '@/lib/drill';

/** The drill path to this page (brief §6.6); nothing when the page was opened directly. `base` is shown when there is no trail. */
const props = defineProps<{ base?: Crumb[] }>();
const trail = ref<Crumb[]>(props.base ?? []);
onMounted(() => {
    const drilled = currentTrail();
    if (drilled.length) trail.value = drilled;
});
</script>

<template>
    <nav v-if="trail.length" aria-label="Breadcrumb" class="text-ui text-ink-2">
        <ol class="flex flex-wrap items-center gap-1">
            <li v-for="(crumb, index) in trail" :key="crumb.href" class="flex items-center gap-1">
                <Link :href="crumb.href" class="hover:text-ink hover:underline">{{ crumb.label }}</Link>
                <span v-if="index < trail.length" aria-hidden="true">›</span>
            </li>
        </ol>
    </nav>
</template>
