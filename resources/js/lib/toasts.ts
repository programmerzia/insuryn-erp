import { reactive } from 'vue';

/** Toasts (UX brief §4 Feedback): bottom-left, 4 seconds, with undo where the action is reversible. */
export interface Toast {
    id: number;
    message: string;
    tone: 'neutral' | 'ok' | 'danger';
    undo?: () => void;
}

export const toasts = reactive<Toast[]>([]);
let next = 1;

export function toast(message: string, options: { tone?: Toast['tone']; undo?: () => void; duration?: number } = {}): void {
    const id = next++;
    toasts.push({ id, message, tone: options.tone ?? 'neutral', undo: options.undo });
    setTimeout(() => dismissToast(id), options.duration ?? 4000);
}

export function dismissToast(id: number): void {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) {
        toasts.splice(index, 1);
    }
}
