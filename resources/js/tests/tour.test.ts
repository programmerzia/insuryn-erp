import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { stepAccess, tourAction, tourSteps } from '@/lib/tour';

/** Session S4 guided tour: the step wiring matches the words in resources/help, and moving through the tour is a pure state change. */
describe('guided tour', () => {
    it('wires every step described in resources/help/tour.en.md, in the same order', () => {
        const ids = [...readFileSync('resources/help/tour.en.md', 'utf8').matchAll(/^## (\S+)$/gm)].map((m) => m[1]);
        expect(tourSteps.map((s) => s.id)).toEqual(ids);
        expect(tourSteps[0]).toMatchObject({ id: 'home', href: '/home' });
    });

    it('knows when a user can do a step, only look at it, or not open its page', () => {
        const step = (id: string) => tourSteps.find((s) => s.id === id)!;
        expect(stepAccess(step('home'), new Set())).toBe('act');
        expect(stepAccess(step('issue-policy'), new Set(['policy.create', 'policy.issue']))).toBe('act');
        expect(stepAccess(step('issue-policy'), new Set(['receipt.allocate']))).toBe('view');
        expect(stepAccess(step('close'), new Set(['policy.issue']))).toBe('none');
    });

    it('starts, moves, dismisses, resumes and finishes', () => {
        const last = tourSteps.length - 1;
        expect(tourAction(null, 'start')).toEqual({ status: 'active', step: 0 });
        expect(tourAction({ status: 'active', step: 2 }, 'next')).toEqual({ status: 'active', step: 3 });
        expect(tourAction({ status: 'active', step: 2 }, 'back')).toEqual({ status: 'active', step: 1 });
        expect(tourAction({ status: 'active', step: 0 }, 'back')).toEqual({ status: 'active', step: 0 });
        expect(tourAction({ status: 'active', step: 4 }, 'dismiss')).toEqual({ status: 'dismissed', step: 4 });
        expect(tourAction({ status: 'dismissed', step: 4 }, 'resume')).toEqual({ status: 'active', step: 4 });
        expect(tourAction({ status: 'active', step: last }, 'next')).toEqual({ status: 'finished', step: last });
        expect(tourAction({ status: 'finished', step: last }, 'start')).toEqual({ status: 'active', step: 0 });
    });
});
