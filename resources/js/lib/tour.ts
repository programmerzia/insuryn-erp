import { requestJson } from '@/lib/http';
import type { TourState } from '@/lib/preferences';

/**
 * Session S4 guided tour of the market cross-check Part A flow. The words live in resources/help/tour.<locale>.md; this is only the wiring:
 * the page each step happens on and the element it spotlights (`data-tour` attribute; the page's main area when it is missing).
 */
export interface TourStep {
    id: string;
    href: string;
    target: string;
}
export interface TourText {
    id: string;
    title: string;
    html: string;
}

export const tourSteps: TourStep[] = [
    { id: 'home', href: '/home', target: 'home-queues' },
    { id: 'issue-policy', href: '/policies/create', target: 'policy-form' },
    { id: 'receive', href: '/receipts/create', target: 'receipt-form' },
    { id: 'suspense', href: '/suspense', target: 'suspense-queue' },
    { id: 'bank', href: '/bank', target: 'bank-accounts' },
    { id: 'register-claim', href: '/claims/create', target: 'claim-form' },
    { id: 'settle-claim', href: '/claims', target: 'claims-queue' },
    { id: 'close', href: '/close', target: 'close-periods' },
];

export type TourMove = 'start' | 'next' | 'back' | 'dismiss' | 'resume';

/** The tour state after a move. Next on the last step finishes; back never goes before the first; dismissing keeps the step for resuming. */
export function tourAction(state: TourState | null, move: TourMove): TourState {
    const step = state?.step ?? 0;
    const last = tourSteps.length - 1;
    switch (move) {
        case 'start':
            return { status: 'active', step: 0 };
        case 'resume':
            return { status: 'active', step: Math.min(step, last) };
        case 'dismiss':
            return { status: 'dismissed', step };
        case 'back':
            return { status: 'active', step: Math.max(0, step - 1) };
        case 'next':
            return step >= last ? { status: 'finished', step: last } : { status: 'active', step: step + 1 };
    }
}

const cache = new Map<string, Promise<TourText[]>>();

export function loadTour(locale: 'en' | 'bn'): Promise<TourText[]> {
    if (!cache.has(locale)) {
        const request = requestJson<{ steps: TourText[] }>('GET', `/help/tour?locale=${locale}`).then((r) => r.steps);
        request.catch(() => cache.delete(locale));
        cache.set(locale, request);
    }
    return cache.get(locale)!;
}
