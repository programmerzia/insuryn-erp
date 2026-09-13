import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/** What the status bar shows (UX brief §3): row count, selection count, sum of selected amounts, pagination. Lists set it; a visit clears it. */
export interface StatusBarState {
    rows: number | null;
    selected: number;
    selectedSum: string | null;
    page: { current: number; last: number; go: (page: number) => void } | null;
    message: string | null;
}

export const statusBar = reactive<StatusBarState>({ rows: null, selected: 0, selectedSum: null, page: null, message: null });

let listening = false;
export function useStatusBar(): StatusBarState {
    if (!listening && typeof window !== 'undefined') {
        listening = true;
        router.on('start', (event) => {
            if (!event.detail.visit.preserveState) {
                Object.assign(statusBar, { rows: null, selected: 0, selectedSum: null, page: null, message: null });
            }
        });
    }
    return statusBar;
}
