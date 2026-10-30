import { describe, expect, it } from 'vitest';
import { splitPlaceholders } from '@/lib/linkedText';

describe('splitPlaceholders', () => {
    it('splits a sentence around known placeholders', () => {
        expect(
            splitPlaceholders('I accept the :terms and the :privacy', [
                'terms',
                'privacy',
            ]),
        ).toEqual([
            { kind: 'text', text: 'I accept the ' },
            { kind: 'link', key: 'terms' },
            { kind: 'text', text: ' and the ' },
            { kind: 'link', key: 'privacy' },
        ]);
    });

    it('keeps unknown placeholders as text and supports Russian word order', () => {
        expect(
            splitPlaceholders('Я даю :consent в соответствии с :law', [
                'consent',
            ]),
        ).toEqual([
            { kind: 'text', text: 'Я даю ' },
            { kind: 'link', key: 'consent' },
            { kind: 'text', text: ' в соответствии с :law' },
        ]);
    });

    it('returns the whole text when nothing matches', () => {
        expect(splitPlaceholders('Plain text', ['terms'])).toEqual([
            { kind: 'text', text: 'Plain text' },
        ]);
    });
});
