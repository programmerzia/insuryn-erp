/** Gap fix GA-22: a segregation-of-duties rule between two permissions (design §7.3); `accounting.*` stands for every accounting permission. */
export interface SodRule { a: string; b: string; mode: string }

function matches(pattern: string, code: string): boolean {
    return pattern.endsWith('.*') ? code.startsWith(pattern.slice(0, -1)) : pattern === code;
}

/** The rules broken by holding every one of `permissions` together, one entry per pair of permissions actually ticked. */
export function sodConflicts(permissions: string[], rules: SodRule[]): { a: string; b: string; mode: string }[] {
    const found: { a: string; b: string; mode: string }[] = [];
    for (const rule of rules) {
        for (const a of permissions.filter((code) => matches(rule.a, code))) {
            for (const b of permissions.filter((code) => matches(rule.b, code) && code !== a)) {
                if (!found.some((f) => f.a === a && f.b === b)) found.push({ a, b, mode: rule.mode });
            }
        }
    }
    return found;
}
