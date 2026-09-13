import { reactive } from 'vue';

/** One confirmation at a time, rendered by ConfirmHost in the shell. Used only where friction is deliberate (brief §1.6) and for leaving unsaved work. */
export interface ConfirmRequest {
    title: string;
    body: string;
    confirmLabel: string;
    cancelLabel?: string;
    tone?: 'primary' | 'danger';
}

export const confirmState = reactive<{ request: ConfirmRequest | null; resolve: ((ok: boolean) => void) | null }>({ request: null, resolve: null });

export function confirmAction(request: ConfirmRequest): Promise<boolean> {
    confirmState.resolve?.(false);
    return new Promise((resolve) => {
        confirmState.request = request;
        confirmState.resolve = resolve;
    });
}

export function settleConfirm(ok: boolean): void {
    confirmState.resolve?.(ok);
    confirmState.request = null;
    confirmState.resolve = null;
}
