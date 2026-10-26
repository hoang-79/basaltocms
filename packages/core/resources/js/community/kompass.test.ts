import { describe, expect, it } from 'vitest';
import { isTyping, nextIndex } from './kompass';

describe('nextIndex', () => {
    it('beginnt vorne oder hinten und bleibt an den Enden stehen', () => {
        expect(nextIndex(-1, 4, 1)).toBe(0);
        expect(nextIndex(-1, 4, -1)).toBe(3);
        expect(nextIndex(2, 4, 1)).toBe(3);
        expect(nextIndex(3, 4, 1)).toBe(3);
        expect(nextIndex(0, 4, -1)).toBe(0);
        expect(nextIndex(0, 0, 1)).toBe(-1);
    });
});

describe('isTyping', () => {
    it('lässt Felder und Editoren in Ruhe', () => {
        expect(isTyping(document.createElement('textarea'))).toBe(true);
        expect(isTyping(document.createElement('input'))).toBe(true);
        const editor = document.createElement('div');
        editor.contentEditable = 'true';
        document.body.append(editor);
        expect(isTyping(editor)).toBe(true);
        expect(isTyping(document.createElement('article'))).toBe(false);
        expect(isTyping(null)).toBe(false);
    });
});
