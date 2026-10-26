import { describe, expect, it } from 'vitest';
import { othersFrom } from './presence';

describe('othersFrom', () => {
    it('lässt die eigene Person und Unbrauchbares weg und rechnet den Ablauf', () => {
        const others = othersFrom(
            {
                personen: [
                    { id: 1, name: 'Ich', rest: 40 },
                    { id: 2, name: 'Anna', rest: 30 },
                    { id: 3, name: '', rest: 30 },
                    { id: '4', name: 'Text', rest: 30 },
                    { id: 5, name: 'Ben', rest: 9999 },
                ],
            },
            1,
            1000,
        );
        expect(others.map((o) => o.person.name)).toEqual(['Anna', 'Ben']);
        expect(others[0]?.until).toBe(31_000);
        expect(others[1]?.until).toBe(121_000);
        expect(othersFrom(null, 1, 0)).toEqual([]);
    });
});
