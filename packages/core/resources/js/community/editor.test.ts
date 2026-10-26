import { describe, expect, it } from 'vitest';
import { docFromText, mentionQuery } from './editor';

describe('docFromText', () => {
    it('macht aus Leerzeilen Absätze und lässt leere weg', () => {
        expect(docFromText('Erster Absatz.\n\n\nZweiter.\n\n  ')).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Erster Absatz.' }],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Zweiter.' }],
                },
            ],
        });
    });

    it('gibt für leeren Text ein leeres Dokument', () => {
        expect(docFromText('   ')).toEqual({ type: 'doc', content: [] });
    });
});

describe('mentionQuery', () => {
    it('findet @name direkt vor der Schreibmarke', () => {
        expect(mentionQuery('Danke @Ann')).toBe('Ann');
        expect(mentionQuery('@Jürg.M')).toBe('Jürg.M');
    });

    it('ignoriert E-Mail-Adressen und Text ohne @', () => {
        expect(mentionQuery('mail an hoang@exam')).toBeNull();
        expect(mentionQuery('kein Name')).toBeNull();
        expect(mentionQuery('Name @ ')).toBeNull();
    });
});
