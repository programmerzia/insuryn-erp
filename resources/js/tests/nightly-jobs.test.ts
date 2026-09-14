import { describe, expect, it } from 'vitest';
import { lastRanLine, type NightlyJob, nightlyHeadline, runSummary } from '@/lib/nightlyJobs';

const job = (key: string, started: string | null, status = 'succeeded', by: string | null = null): NightlyJob => ({
    key,
    label: key,
    does: '',
    at: '01:00',
    last: started === null ? null : { status, started_at: started, finished_at: started, when: `when ${started}`, by, error: null, summary: {} },
});

describe('nightly jobs on the close screen (GA-05)', () => {
    it('says when the jobs last ran by the most out-of-date one, and flags jobs that never ran or failed', () => {
        expect(nightlyHeadline([job('a', '2026-09-14 19:00:00+00'), job('b', '2026-09-13 19:00:00+00')])).toBe('Nightly jobs last ran when 2026-09-13 19:00:00+00');
        expect(nightlyHeadline([job('a', null), job('b', null)])).toBe('Nightly jobs have not run yet');
        expect(nightlyHeadline([job('a', '2026-09-14 19:00:00+00'), job('b', null)])).toBe('1 of 2 nightly jobs have not run yet');
        expect(nightlyHeadline([job('a', '2026-09-14 19:00:00+00', 'failed'), job('b', '2026-09-14 19:00:00+00')])).toBe('1 nightly job failed');
    });

    it('describes what a run did and who started it', () => {
        expect(runSummary({ activated: 3, expired: 0, periods_earned: 1 })).toBe('3 activated, 0 expired, 1 periods earned');
        expect(runSummary({ count: 2 })).toBe('2 done');
        expect(lastRanLine(null)).toBe('Never ran');
        expect(lastRanLine(job('a', 'x').last)).toBe('Last ran when x on the schedule');
        expect(lastRanLine(job('a', 'x', 'succeeded', 'Finance manager').last)).toBe('Last ran when x by Finance manager');
    });
});
