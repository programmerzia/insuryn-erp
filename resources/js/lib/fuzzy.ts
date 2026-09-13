/**
 * Fuzzy score for the command palette: every query character must appear in order. Consecutive matches, matches at word starts and a
 * match at the very start score higher. 0 = no match; an empty query matches everything with 1.
 */
export function fuzzyScore(query: string, text: string): number {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return 1;
    }
    const t = text.toLowerCase();
    if (t.includes(q)) {
        const at = t.indexOf(q);
        return 1000 - at + (at === 0 || /[\s\-·/]/.test(t[at - 1] ?? '') ? 500 : 0);
    }
    let score = 0;
    let ti = 0;
    let streak = 0;
    for (const ch of q) {
        if (ch === ' ') {
            continue;
        }
        const found = t.indexOf(ch, ti);
        if (found === -1) {
            return 0;
        }
        const wordStart = found === 0 || /[\s\-·/]/.test(t[found - 1] ?? '');
        streak = found === ti ? streak + 1 : 0;
        score += 1 + streak * 2 + (wordStart ? 6 : 0);
        ti = found + 1;
    }
    return score;
}
