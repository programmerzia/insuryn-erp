import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types/shared';

/**
 * Session S6: whether the tenant still needs setting up (no products yet), whether this user can do a setup step, and — locally, while the tenant has
 * no policies — the command that loads the Part A demo story. Empty screens use it to point to the setup wizard or the demo.
 */
export function useOnboarding() {
    const page = usePage<SharedProps>();
    return computed(() => page.props.shell?.onboarding ?? { setupNeeded: false, canSetup: false, demoCommand: null });
}
