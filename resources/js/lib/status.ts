/** Status words and tones (brief §2: ok = posted/matched/reconciled, warn = pending/ageing/soft-locked, danger = failed/unbalanced/violations only). */
export type StatusTone = 'ok' | 'warn' | 'danger' | 'neutral';

const ok = new Set(['posted', 'matched', 'reconciled', 'paid', 'released', 'allocated', 'done', 'completed', 'active', 'issued', 'approved', 'balanced', 'renewed', 'succeeded', 'cleared', 'filed']); // market gap G5: filed returns // GA-14: cleared cheques
const warn = new Set(['pending', 'pending_approval', 'queued', 'posting', 'draft', 'quote', 'requested', 'release_requested', 'partially_allocated', 'unallocated', 'soft_locked', 'in_progress', 'registered', 'reserved', 'lapsed', 'skipped', 'waiting', 'blocked', 'renewal_offered', 'expired_not_renewed', 'invited', 'running', 'in_clearing', 'reviewed']); // market gap G5: a reviewed return or provisions run waits for its next step // GA-14: cheques in clearing
const danger = new Set(['failed', 'unbalanced', 'variance', 'bounced']);

export function statusTone(status: string): StatusTone {
    if (danger.has(status)) return 'danger';
    if (warn.has(status)) return 'warn';
    if (ok.has(status)) return 'ok';
    return 'neutral';
}

/** GA-37 (docs/glossary.md; ASSUMPTION: A-200): status words that read differently from their stored value. */
const STATUS_WORDS: Record<string, string> = { expired_not_renewed: 'Expired, not renewed', not_generated: 'Not generated' };

/** "pending_approval" → "Pending approval" (sentence case). */
export function statusWord(status: string): string {
    if (STATUS_WORDS[status]) return STATUS_WORDS[status];
    const words = status.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}
