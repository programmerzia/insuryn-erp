/** Gap fix GA-05: the nightly jobs panel on the close screen (App\Http\Close\NightlyJobs::panel). */
export interface NightlyJobRun {
    status: string;
    started_at: string;
    finished_at: string | null;
    /** Start time on the business clock, "14 Sep 2026, 01:00". */
    when: string;
    by: string | null;
    error: string | null;
    summary: Record<string, number | string>;
}
export interface NightlyJob {
    key: string;
    label: string;
    does: string;
    at: string;
    last: NightlyJobRun | null;
}
export interface NightlyJobsPanel {
    can_run: boolean;
    zone: string;
    jobs: NightlyJob[];
}

/** The toolbar line: the oldest last run decides, because that is the job most out of date; a job that never ran says so. */
export function nightlyHeadline(jobs: NightlyJob[]): string {
    if (jobs.length === 0) return 'No nightly jobs';
    const never = jobs.filter((job) => job.last === null).length;
    if (never === jobs.length) return 'Nightly jobs have not run yet';
    if (never > 0) return `${never} of ${jobs.length} nightly jobs have not run yet`;
    const failed = jobs.filter((job) => job.last?.status === 'failed').length;
    if (failed > 0) return `${failed} nightly ${failed === 1 ? 'job' : 'jobs'} failed`;
    const oldest = [...jobs].sort((a, b) => (a.last!.started_at < b.last!.started_at ? -1 : 1))[0]!;
    return `Nightly jobs last ran ${oldest.last!.when}`;
}

/** { activated: 3, expired: 0 } → "3 activated, 0 expired"; { count: 2 } → "2 done". */
export function runSummary(summary: Record<string, number | string>): string {
    return Object.entries(summary)
        .map(([key, value]) => `${value} ${key === 'count' ? 'done' : key.replaceAll('_', ' ')}`)
        .join(', ');
}

/** "Last ran 14 Sep 2026, 01:00 by the schedule" / "… by Finance manager" / "Never ran". */
export function lastRanLine(run: NightlyJobRun | null): string {
    if (run === null) return 'Never ran';
    return `Last ran ${run.when} ${run.by ? `by ${run.by}` : 'on the schedule'}`;
}
