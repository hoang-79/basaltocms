import { describe, expect, it } from 'vitest';
import { applyTheme, isDark } from './theme';

/** TB 21: dunkle Oberfläche — hell, dunkel, wie das System. */
describe('theme', () => {
    it('entscheidet nach Wahl und System', () => {
        expect(isDark('light', true)).toBe(false);
        expect(isDark('dark', false)).toBe(true);
        expect(isDark('system', true)).toBe(true);
        expect(isDark('system', false)).toBe(false);
    });

    it('setzt data-theme am html und nimmt es wieder weg', () => {
        applyTheme('dark');
        expect(document.documentElement.dataset.theme).toBe('dark');
        expect(document.documentElement.dataset.themePref).toBe('dark');
        applyTheme('light');
        expect(document.documentElement.dataset.theme).toBeUndefined();
        applyTheme('unsinn');
        expect(document.documentElement.dataset.themePref).toBe('light');
    });
});
