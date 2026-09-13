import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types/shared';

/** Show an action only to people who may take it (the server enforces it regardless). */
export function usePermissions() {
    const page = usePage<SharedProps>();
    const held = computed(() => new Set(page.props.auth.permissions ?? []));
    return { can: (...any: string[]) => any.some((p) => held.value.has(p)) };
}
