import { describe, expect, it } from 'vitest';
import { toDoc } from './doc';

describe('toDoc', () => {
    it('lässt ein Dokument, wie es ist', () => {
        const doc = { type: 'doc', content: [{ type: 'paragraph' }] };

        expect(toDoc(doc)).toBe(doc);
    });

    it('macht aus Klartext Absätze und Umbrüche', () => {
        expect(toDoc('Erste Zeile\nzweite Zeile\n\nNeuer Absatz')).toEqual({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [
                        { type: 'text', text: 'Erste Zeile' },
                        { type: 'hardBreak' },
                        { type: 'text', text: 'zweite Zeile' },
                    ],
                },
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Neuer Absatz' }],
                },
            ],
        });
    });

    it('gibt für Leeres und Fremdes nichts', () => {
        expect(toDoc('  ')).toBeNull();
        expect(toDoc(null)).toBeNull();
        expect(toDoc({ href: '/x' })).toBeNull();
        expect(toDoc(['a'])).toBeNull();
    });
});
