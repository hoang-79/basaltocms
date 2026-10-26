import { describe, expect, it } from 'vitest';
import { changedKeys, isSaved, labelFor } from './autosave';

describe('Autosave', () => {
    it('findet die geänderten Felder, auch verschachtelt', () => {
        expect(changedKeys({ a: 1, b: 'x' }, { a: 1, b: 'y' })).toEqual(['b']);
        expect(
            changedKeys(
                { alt: { de: 'a', en: '' } },
                { alt: { de: 'a', en: 'b' } },
            ),
        ).toEqual(['alt.en']);
        expect(changedKeys({ list: [1, 2] }, { list: [1, 3] })).toEqual([
            'list.1',
        ]);
        expect(
            changedKeys(
                { content: [{ t: 'a' }, { t: 'b' }] },
                { content: [{ t: 'a' }, { t: 'c' }, { t: 'd' }] },
            ),
        ).toEqual(['content.1.t', 'content.2']);
        expect(changedKeys({ a: 1 }, { a: 1 })).toEqual([]);
    });

    it('ordnet gespeicherte Schlüssel den Feldern zu', () => {
        expect(isSaved(['alt.en'], 'alt')).toBe(true);
        expect(isSaved(['content'], 'content.0.data.title')).toBe(true);
        expect(isSaved(['first_name'], 'last_name')).toBe(false);
    });

    it('findet die Beschriftung über den längsten Präfix', () => {
        const labels = {
            title: 'Titel',
            'values.content.1.data.body': 'Fliesstext',
            values: 'Übersetzung',
        };
        expect(
            labelFor(
                'values.content.1.data.body.content.0.content.0.text',
                labels,
            ),
        ).toBe('Fliesstext');
        expect(labelFor('values.title', labels)).toBe('Übersetzung');
        expect(labelFor('slug', labels)).toBeNull();
    });
});
