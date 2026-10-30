function csrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export class JsonRequestError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: Record<string, unknown> | null,
    ) {
        super(
            typeof body?.message === 'string' ? body.message : String(status),
        );
    }
}

export async function jsonRequest<T>(
    url: string,
    method: 'GET' | 'POST' = 'GET',
    body?: Record<string, unknown>,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = csrfToken();

    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers,
    };

    if (method !== 'GET' && body) {
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);

    let parsed: unknown = null;

    try {
        parsed = await response.json();
    } catch {
        parsed = null;
    }

    if (!response.ok) {
        throw new JsonRequestError(
            response.status,
            parsed && typeof parsed === 'object'
                ? (parsed as Record<string, unknown>)
                : null,
        );
    }

    return parsed as T;
}
