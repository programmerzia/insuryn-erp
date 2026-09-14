import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types/shared';

/**
 * Gap fix GA-07: the permissions a screen opens for (the server's page controllers' AREA constants), so a link is shown only to people it opens for.
 */
export const AREAS = {
    commission: ['commission.manage_plans', 'commission.approve', 'commission.pay', 'reports.financial'],
    quotes: ['quotation.create', 'policy.create'],
    policies: ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', 'reports.financial'],
    bank: ['bank.import', 'bank.match', 'bank.manage_accounts', 'reports.financial'],
} as const;

/** Show an action only to people who may take it (the server enforces it regardless). */
export function usePermissions() {
    const page = usePage<SharedProps>();
    const held = computed(() => new Set(page.props.auth.permissions ?? []));
    return { can: (...any: string[]) => any.some((p) => held.value.has(p)) };
}
