export async function fetchPartial<T>(
    url: string,
    component: string,
    prop: string,
    version: string | null | undefined,
): Promise<T | null> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'text/html, application/xhtml+xml',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Inertia': 'true',
            'X-Inertia-Version': version ?? '',
            'X-Inertia-Partial-Component': component,
            'X-Inertia-Partial-Data': prop,
        },
    });

    if (!response.ok) {
        return null;
    }

    const payload = (await response.json()) as {
        props?: Record<string, unknown>;
    };

    return (payload.props?.[prop] as T | undefined) ?? null;
}
