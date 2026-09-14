import type { Preferences } from '@/lib/preferences';

/** Props HandleInertiaRequests shares with every page. */
export interface SharedProps {
    auth: { user: { id: string; name: string; email: string } | null; permissions?: string[] };
    tenant: { id: string; name: string; slug: string } | null;
    preferences: Preferences;
    shell: {
        entity: { code: string; name: string; currency: string } | null;
        branches: { id: string; code: string; name: string }[];
        approvals: number;
        badges: Record<string, number>;
        onboarding: { setupNeeded: boolean; canSetup: boolean; demoCommand: string | null };
    } | null;
    status: string | null;
    /** UX U1: the date picker's first day of the week, `sunday` … `saturday` (erp.ui.week_starts_on, A-165). */
    calendar?: { week_starts_on: string };
    errors: Record<string, string>;
    [key: string]: unknown;
}
