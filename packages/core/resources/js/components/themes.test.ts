import { describe, expect, it } from 'vitest';
import { fileSize, freeThemeName, isThemeName, themeSlug } from './themes';

describe('Namen der Theme-Bibliothek', () => {
    it('macht aus einer Bezeichnung einen Ordnernamen', () => {
        expect(themeSlug('Café Süd')).toBe('cafe-sued');
        expect(themeSlug('  Kopie von Basalto!  ')).toBe('kopie-von-basalto');
        expect(themeSlug('Ärzte & Öl')).toBe('aerzte-oel');
        expect(themeSlug('x'.repeat(80))).toHaveLength(60);
    });

    it('prüft Namen wie der Server', () => {
        expect(isThemeName('mein-theme')).toBe(true);
        expect(isThemeName('-theme')).toBe(false);
        expect(isThemeName('Theme')).toBe(false);
        expect(isThemeName('a/b')).toBe(false);
    });

    it('zählt hoch, solange der Name vergeben ist', () => {
        expect(freeThemeName('Starter', [])).toBe('starter');
        expect(freeThemeName('Starter', ['starter', 'starter-2'])).toBe(
            'starter-3',
        );
        expect(freeThemeName('', [])).toBe('theme');
    });

    it('zeigt Dateigrössen lesbar', () => {
        expect(fileSize(512)).toBe('512 B');
        expect(fileSize(12 * 1024)).toBe('12 KB');
        // de-CH schreibt den Dezimalpunkt, fr-CH das Komma
        expect(fileSize(3.4 * 1024 * 1024)).toBe('3.4 MB');
        expect(fileSize(3.4 * 1024 * 1024, 'fr')).toBe('3,4 MB');
    });
});
