/** Status words and tones (brief §2: ok = posted/matched/reconciled, warn = pending/ageing/soft-locked, danger = failed/unbalanced/violations only). */
export type StatusTone = 'ok' | 'warn' | 'danger' | 'neutral';

const ok = new Set(['posted', 'matched', 'reconciled', 'paid', 'released', 'allocated', 'done', 'completed', 'active', 'issued', 'approved', 'balanced']);
const warn = new Set(['pending', 'pending_approval', 'queued', 'posting', 'draft', 'quote', 'requested', 'release_requested', 'partially_allocated', 'unallocated', 'soft_locked', 'in_progress', 'registered', 'reserved', 'lapsed', 'skipped', 'waiting', 'blocked']);
const danger = new Set(['failed', 'unbalanced', 'variance', 'bounced']);

export function statusTone(status: string): StatusTone {
    if (danger.has(status)) return 'danger';
    if (warn.has(status)) return 'warn';
    if (ok.has(status)) return 'ok';
    return 'neutral';
}

/** "pending_approval" → "Pending approval" (sentence case). */
export function statusWord(status: string): string {
    const words = status.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}
