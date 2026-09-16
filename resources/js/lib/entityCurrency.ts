import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/** Entity base currency from shell props (legal_entities.base_currency). */
export function useEntityCurrency(): ComputedRef<string> {
    const page = usePage<{ shell?: { entity?: { currency?: string } | null } }>();

    return computed(() => page.props.shell?.entity?.currency ?? 'KES');
}
