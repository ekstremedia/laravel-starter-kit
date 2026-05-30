/**
 * Fetch a JSON resource, returning null on any non-OK response or error.
 * Used by live-list updates to pull a single changed row. Same-origin GET, so
 * no CSRF token is needed.
 */
export async function fetchJson<T>(url: string): Promise<T | null> {
    try {
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            return null;
        }
        return (await res.json()) as T;
    } catch {
        return null;
    }
}
