/**
 * Gap fix GA-07: "Ask for access" on a refused page opens an email to the organisation's administrators, naming the page and what it needs.
 * Null when nobody who can grant access is known.
 */
export function accessRequestHref(access: { admins: { name: string; email: string }[]; subject: string }, pageUrl: string): string | null {
    const to = access.admins.map((admin) => admin.email).filter((email) => email !== '');
    if (to.length === 0) return null;
    const body = `Please give me access to ${pageUrl}.`;
    return `mailto:${to.join(',')}?subject=${encodeURIComponent(access.subject)}&body=${encodeURIComponent(body)}`;
}
