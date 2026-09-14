/**
 * Gap fix GA-18: setup wizard helpers. Pure functions, no Vue.
 */

const LEGAL_WORDS = ['LTD', 'LIMITED', 'PLC', 'CO', 'COMPANY', 'THE', 'AND', 'OF', 'PVT', 'PRIVATE', 'INC'];

/** The short code suggested from a company name, as the server suggests it (CompanySetup::suggestCode): initials without legal words, or a one-word name's first four letters. */
export function suggestCompanyCode(name: string): string {
    const words = name.toUpperCase().split(/[^A-Z0-9]+/).filter((w) => w !== '');
    const meaningful = words.filter((w) => !LEGAL_WORDS.includes(w));
    const chosen = meaningful.length ? meaningful : words;
    if (!chosen.length) return '';
    const code = chosen.length === 1 ? (chosen[0] ?? '').slice(0, 4) : chosen.map((w) => w[0]).join('');
    return code.slice(0, 16);
}

/** How the Done step names a step's state: saved, still to do (by this user), or left for the role that owns it. */
export function stepState(step: { done: boolean; allowed: boolean; owner: string | null }): string {
    if (step.done) return 'Saved';
    return step.allowed ? 'Not saved yet' : `Left for the ${step.owner ?? 'owner'}`;
}
