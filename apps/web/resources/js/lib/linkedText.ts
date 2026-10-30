export type LinkedPart =
    | { kind: 'text'; text: string }
    | { kind: 'link'; key: string };

export function splitPlaceholders(
    template: string,
    keys: readonly string[],
): LinkedPart[] {
    const parts: LinkedPart[] = [];
    const pattern = /:([a-z_]+)/g;
    let last = 0;

    for (const match of template.matchAll(pattern)) {
        const key = match[1] ?? '';

        if (!keys.includes(key)) {
            continue;
        }

        const index = match.index ?? 0;

        if (index > last) {
            parts.push({ kind: 'text', text: template.slice(last, index) });
        }

        parts.push({ kind: 'link', key });
        last = index + match[0].length;
    }

    if (last < template.length) {
        parts.push({ kind: 'text', text: template.slice(last) });
    }

    return parts;
}
