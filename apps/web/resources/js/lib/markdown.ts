export type Inline = { text: string; strong: boolean; code: boolean };

export type MarkdownBlock =
    | { kind: 'heading'; level: 1 | 2 | 3; text: string; inlines: Inline[] }
    | { kind: 'paragraph'; inlines: Inline[] }
    | { kind: 'list'; ordered: boolean; items: Inline[][] }
    | { kind: 'quote'; inlines: Inline[] };

export function stripFrontMatter(source: string): string {
    const normalized = source.replace(/\r\n?/g, '\n');

    if (!normalized.startsWith('---\n')) {
        return normalized;
    }

    const end = normalized.indexOf('\n---', 4);

    if (end === -1) {
        return normalized;
    }

    const after = normalized.indexOf('\n', end + 4);

    return after === -1 ? '' : normalized.slice(after + 1);
}

export function parseInline(text: string): Inline[] {
    const out: Inline[] = [];
    const pattern = /(\*\*[^*]+\*\*|`[^`]+`)/g;
    let last = 0;

    for (const match of text.matchAll(pattern)) {
        const index = match.index ?? 0;

        if (index > last) {
            out.push({
                text: text.slice(last, index),
                strong: false,
                code: false,
            });
        }

        const token = match[0];

        out.push(
            token.startsWith('**')
                ? { text: token.slice(2, -2), strong: true, code: false }
                : { text: token.slice(1, -1), strong: false, code: true },
        );
        last = index + token.length;
    }

    if (last < text.length) {
        out.push({ text: text.slice(last), strong: false, code: false });
    }

    return out;
}

export function parseMarkdown(source: string): MarkdownBlock[] {
    const lines = stripFrontMatter(source).split('\n');
    const blocks: MarkdownBlock[] = [];
    let paragraph: string[] = [];
    let list: { ordered: boolean; items: Inline[][] } | null = null;
    let quote: string[] = [];

    const flush = (): void => {
        if (paragraph.length) {
            blocks.push({
                kind: 'paragraph',
                inlines: parseInline(paragraph.join(' ')),
            });
            paragraph = [];
        }

        if (list) {
            blocks.push({ kind: 'list', ...list });
            list = null;
        }

        if (quote.length) {
            blocks.push({
                kind: 'quote',
                inlines: parseInline(quote.join(' ')),
            });
            quote = [];
        }
    };

    for (const raw of lines) {
        const line = raw.trim();

        if (!line) {
            flush();
            continue;
        }

        const heading = /^(#{1,3})\s+(.+)$/.exec(line);

        if (heading) {
            flush();
            const text = heading[2].trim();
            blocks.push({
                kind: 'heading',
                level: heading[1].length as 1 | 2 | 3,
                text,
                inlines: parseInline(text),
            });
            continue;
        }

        const numbered = /^\d+[.)]\s+(.+)$/.exec(line);
        const item = /^[-*]\s+(.+)$/.exec(line) ?? numbered;

        if (item) {
            const ordered = numbered !== null;

            if (
                paragraph.length ||
                quote.length ||
                (list && list.ordered !== ordered)
            ) {
                flush();
            }

            list ??= { ordered, items: [] };
            list.items.push(parseInline(item[1].trim()));
            continue;
        }

        if (line.startsWith('>')) {
            if (paragraph.length || list) {
                flush();
            }

            quote.push(line.replace(/^>\s?/, ''));
            continue;
        }

        if (list || quote.length) {
            flush();
        }

        paragraph.push(line);
    }

    flush();

    return blocks;
}
