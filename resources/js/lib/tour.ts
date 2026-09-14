import { requestJson } from '@/lib/http';
import { navigation } from '@/lib/navigation';
import type { TourState } from '@/lib/preferences';

/**
 * Session S4 guided tour of the market cross-check Part A flow. The words live in resources/help/tour.<locale>.md; this is only the wiring:
 * the page each step happens on and the element it spotlights (`data-tour` attribute; the page's main area when it is missing).
 */
export interface TourStep {
    id: string;
    href: string;
    target: string;
    /** Sidebar item whose page the step is on (its area permissions decide whether the page opens). */
    area: string;
    /** Permission to do the step for real, and the §7.2 role template that holds it (steps follow segregation of duties, so no one person does them all). */
    act: string | null;
    role: string | null;
}
export type StepAccess = 'act' | 'view' | 'none';
export interface TourText {
    id: string;
    title: string;
    html: string;
}

export const tourSteps: TourStep[] = [
    { id: 'home', href: '/home', target: 'home-queues', area: 'home', act: null, role: null },
    { id: 'quote', href: '/quotations/create', target: 'quote-workbench', area: 'quotes', act: 'quotation.create', role: 'branch_officer' },
    { id: 'referrals', href: '/underwriting/referrals', target: 'referrals-queue', area: 'referrals', act: 'underwriting.decide', role: 'branch_manager' },
    { id: 'receive', href: '/receipts/create', target: 'receipt-form', area: 'receipts', act: 'receipt.create', role: 'branch_officer' },
    { id: 'suspense', href: '/suspense', target: 'suspense-queue', area: 'suspense', act: 'receipt.allocate', role: 'accountant' },
    { id: 'bank', href: '/bank', target: 'bank-accounts', area: 'bank', act: 'bank.import', role: 'accountant' },
    { id: 'register-claim', href: '/claims/create', target: 'claim-form', area: 'claims', act: 'claim.register', role: 'claims_officer' },
    { id: 'settle-claim', href: '/claims', target: 'claims-queue', area: 'claims', act: 'claim.reserve', role: 'claims_officer' },
    { id: 'close', href: '/close', target: 'close-periods', area: 'close', act: 'periods.lock', role: 'finance_manager' },
];

/** Whether the user can do a step, only look at its page, or not even open the page (then the tour stays put and says who does the step). */
export function stepAccess(step: TourStep, held: ReadonlySet<string>): StepAccess {
    const area = navigation.find((item) => item.id === step.area)?.any ?? [];
    if (area.length > 0 && !area.some((permission) => held.has(permission))) return 'none';
    return step.act === null || held.has(step.act) ? 'act' : 'view';
}

export const roleNames: Record<string, string> = { branch_officer: 'Branch Officer', branch_manager: 'Branch Manager', accountant: 'Accountant', claims_officer: 'Claims Officer', finance_manager: 'Finance Manager' };

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
