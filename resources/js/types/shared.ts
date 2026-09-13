/** Props HandleInertiaRequests shares with every page. */
export interface SharedProps {
    auth: { user: { id: string; name: string; email: string } | null };
    tenant: { id: string; name: string; slug: string } | null;
    status: string | null;
    errors: Record<string, string>;
    [key: string]: unknown;
}
