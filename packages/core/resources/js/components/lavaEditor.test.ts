import { describe, expect, it } from 'vitest';
import {
    countFindings,
    findingsText,
    isValidThemePath,
    plainMarkdown,
    rangeOf,
    severityOf,
} from './lavaEditor';
import type { Finding } from './lavaEditor';

const finding = (severity: string): Finding => ({
    file: 'theme.lava',
    line: 1,
    column: 1,
    character: 0,
    severity,
    message: 'x',
});

describe('Lava-Editor', () => {
    it('übersetzt die Schweregrade des Compilers', () => {
        expect(severityOf('fehler')).toBe('error');
        expect(severityOf('warnung')).toBe('warning');
        expect(severityOf('hinweis')).toBe('info');
    });

    it('zählt Befunde und sagt «fehlerfrei»', () => {
        const list = [
            finding('fehler'),
            finding('warnung'),
            finding('warnung'),
        ];
        expect(countFindings(list)).toEqual({ errors: 1, warnings: 2 });
        expect(findingsText(list)).toBe('1 Fehler, 2 Warnungen');
        expect(findingsText([finding('warnung')])).toBe('1 Warnung');
        expect(findingsText([])).toBe('fehlerfrei');
    });

    it('markiert das Wort an der Stelle, auch mit Umlauten davor', () => {
        const text = 'block hero {\n  label: «Bühne» itmes\n}';
        const second = text.indexOf('\n') + 1;
        const at = text.indexOf('itmes') - second;
        expect(rangeOf(text, 2, at)).toEqual({
            from: second + at,
            to: second + at + 5,
        });
        expect(rangeOf(text, 99, 0).from).toBe(text.lastIndexOf('\n') + 1);
        expect(rangeOf('', 1, 5)).toEqual({ from: 0, to: 0 });
    });

    it('macht Markdown zu Text und prüft neue Pfade', () => {
        expect(plainMarkdown('**hero** — `text`')).toBe('hero — text');
        expect(isValidThemePath('templates/seite.lava')).toBe(true);
        expect(isValidThemePath('assets/theme.css')).toBe(true);
        expect(isValidThemePath('blocks/cards.lava.html')).toBe(true);
        expect(isValidThemePath('.env.css')).toBe(false);
        expect(isValidThemePath('../.env')).toBe(false);
        expect(isValidThemePath('bild.png')).toBe(false);
    });
});
