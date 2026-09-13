/** JSON requests outside Inertia visits (preferences, lookups, previews). Sends Laravel's XSRF token from its cookie. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1] ?? '') : '';
}

export class HttpError extends Error {
    constructor(public status: number, public body: unknown) {
        super(`Request failed with status ${status}`);
    }
}

export async function requestJson<T>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', url: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        method,
        signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await response.text();
    const parsed: unknown = text === '' ? null : JSON.parse(text);
    if (!response.ok) {
        throw new HttpError(response.status, parsed);
    }
    return parsed as T;
}
