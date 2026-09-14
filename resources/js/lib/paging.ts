import { router } from '@inertiajs/vue3';
import type { ServerPage } from '@/components/table/types';

/** A Laravel page as the list controllers share it (PageSupport::page). */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

/** The URL of page `page` of the current list: the query (filters, sort, other parameters) is kept; page 1 drops the parameter. */
export function pageUrl(href: string, page: number): string {
    const url = new URL(href, 'http://local');
    if (page <= 1) url.searchParams.delete('page');
    else url.searchParams.set('page', String(page));
    const query = url.searchParams.toString();
    return `${url.pathname}${query ? `?${query}` : ''}`;
}

/**
 * Gap audit GA-40: the line a table shows when it holds only part of the server's rows — a page of a paged list, or the first rows of a capped one;
 * null when every row is there.
 */
export function partialNotice(shown: number, page?: { current: number; last: number; total: number } | null, total?: number | null): string | null {
    const count = (n: number) => n.toLocaleString('en-US');
    if (page && page.total > shown) {
        return `Showing ${count(shown)} of ${count(page.total)} · page ${count(page.current)} of ${count(page.last)}; the pager is in the status bar. Filters and totals cover this page.`;
    }
    if (!page && typeof total === 'number' && total > shown) {
        return `Showing the first ${count(shown)} of ${count(total)}.`;
    }
    return null;
}

/**
 * Gap audit GA-40: a server-paged list hands this to QueueView/DataTable, which shows "Showing N of M" and the pager in the status bar, instead of
 * stopping silently at the page size.
 */
export function serverPage<T>(paginated: Paginated<T>, visit: (url: string) => void = (url) => router.visit(url, { preserveScroll: false })): ServerPage {
    return {
        current: paginated.current_page,
        last: paginated.last_page,
        total: paginated.total,
        go: (page: number) => visit(pageUrl(typeof window === 'undefined' ? '/' : `${window.location.pathname}${window.location.search}`, Math.max(1, Math.min(page, paginated.last_page)))),
    };
}
