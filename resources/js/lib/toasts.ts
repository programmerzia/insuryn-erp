import { reactive } from 'vue';

/** Toasts (UX brief §4 Feedback): bottom-left, 4 seconds, with undo where the action is reversible, or the next step where the work continues. */
export interface Toast {
    id: number;
    message: string;
    tone: 'neutral' | 'ok' | 'danger';
    undo?: () => void;
    /** Flow audit: the next step offered with the confirmation, e.g. "Record receipt" after issuing a policy. */
    action?: { label: string; run: () => void };
}

export const toasts = reactive<Toast[]>([]);
let next = 1;

export function toast(message: string, options: { tone?: Toast['tone']; undo?: () => void; action?: Toast['action']; duration?: number } = {}): void {
    const id = next++;
    toasts.push({ id, message, tone: options.tone ?? 'neutral', undo: options.undo, action: options.action });
    setTimeout(() => dismissToast(id), options.duration ?? 4000);
}

export function dismissToast(id: number): void {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) {
        toasts.splice(index, 1);
    }
}

/** A step the server offers after a completed action (HandleInertiaRequests `next`). */
export interface NextStep {
    label: string;
    url: string;
    prompt?: string | null;
}

/**
 * The confirmation toast for a status flash: the message, followed by the next step's question when one is offered ("Policy POL-… issued. Record the
 * premium receipt?") with its action; an undo when the action is reversible. Longer on screen when there is something to click.
 */
export function confirmationToast(status: string, undo: { label: string; url: string } | null | undefined, step: NextStep | null | undefined, visit: (url: string) => void, reverse: (url: string) => void): {
    message: string; options: { tone: 'ok'; undo?: () => void; action?: Toast['action']; duration: number };
} {
    const action = step ? { label: step.label, run: () => visit(step.url) } : undefined;
    const message = step?.prompt ? `${status} ${step.prompt}` : status;
    return { message, options: { tone: 'ok', undo: undo ? () => reverse(undo.url) : undefined, action, duration: action ? 10000 : undo ? 6000 : 4000 } };
}
