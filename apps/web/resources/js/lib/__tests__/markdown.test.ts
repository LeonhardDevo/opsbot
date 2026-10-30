import { describe, expect, it } from 'vitest';
import { parseInline, parseMarkdown, stripFrontMatter } from '@/lib/markdown';

const NOTICE = [
    '---',
    'title: Employee notice',
    'version: "1.0"',
    '---',
    '',
    '> Template — review before use.',
    '',
    '# Employee notice',
    '',
    'Your employer uses OpsBot',
    'in Telegram.',
    '',
    '## What is stored',
    '',
    '- Your **questions** and answers.',
    '- Incidents you report.',
    '',
    '1. First',
    '2. Second',
    'Commands: `/privacy`.',
].join('\r\n');

describe('stripFrontMatter', () => {
    it('removes the YAML header and normalises line endings', () => {
        const body = stripFrontMatter(NOTICE);

        expect(body.startsWith('\n> Template')).toBe(true);
        expect(body).not.toContain('\r');
    });

    it('keeps text without a header untouched', () => {
        expect(stripFrontMatter('# Title')).toBe('# Title');
        expect(stripFrontMatter('---\nunterminated')).toBe('---\nunterminated');
    });
});

describe('parseInline', () => {
    it('splits bold and code runs', () => {
        expect(parseInline('a **b** `c` d')).toEqual([
            { text: 'a ', strong: false, code: false },
            { text: 'b', strong: true, code: false },
            { text: ' ', strong: false, code: false },
            { text: 'c', strong: false, code: true },
            { text: ' d', strong: false, code: false },
        ]);
    });
});

describe('parseMarkdown', () => {
    it('builds headings, paragraphs, lists and quotes in order', () => {
        const blocks = parseMarkdown(NOTICE);

        expect(blocks.map((block) => block.kind)).toEqual([
            'quote',
            'heading',
            'paragraph',
            'heading',
            'list',
            'list',
            'paragraph',
        ]);
        expect(blocks[1]).toMatchObject({ level: 1, text: 'Employee notice' });
        expect(blocks[2]).toMatchObject({
            inlines: [{ text: 'Your employer uses OpsBot in Telegram.' }],
        });
        expect(blocks[4]).toMatchObject({ ordered: false });
        expect(blocks[5]).toMatchObject({ ordered: true });
        expect(blocks[4].kind === 'list' && blocks[4].items).toHaveLength(2);
    });

    it('never produces markup from the source text', () => {
        const blocks = parseMarkdown('<script>alert(1)</script>');

        expect(blocks).toEqual([
            {
                kind: 'paragraph',
                inlines: [
                    {
                        text: '<script>alert(1)</script>',
                        strong: false,
                        code: false,
                    },
                ],
            },
        ]);
    });
});
