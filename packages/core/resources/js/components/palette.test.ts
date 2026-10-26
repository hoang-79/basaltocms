import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { contrast } from './design';

/**
 * Die Kontrasttabelle des Backends (barrierefreiheit-tb08.md) als Prüfung: jede Kombination aus `@theme` in
 * backend.css, die im Backend vorkommt — Text mit 4,5:1 (WCAG 1.4.3), Rahmen, Fokus und Schalter mit 3:1 (1.4.11).
 * Wer eine Farbe ändert, sieht hier, welche Verwendung kippt.
 */
const css = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../css/backend.css'),
    'utf8',
);
const colors = Object.fromEntries(
    [...css.matchAll(/--color-([a-z-]+):\s*(#[0-9a-f]{6});/g)].map((m) => [
        m[1],
        m[2],
    ]),
) as Record<string, string>;

/** Ein Farbname aus @theme, `weiss`, oder `farbe/10 auf grund` (Tailwind-Tönung über einem Grund). */
function color(name: string): string {
    if (name === 'weiss') {
        return '#ffffff';
    }
    const tint = /^([a-z-]+)\/(\d+) auf ([a-z-]+)$/.exec(name);
    if (tint) {
        return mix(
            color(tint[1] ?? ''),
            color(tint[3] ?? ''),
            Number(tint[2]) / 100,
        );
    }
    const value = colors[name];
    if (!value) {
        throw new Error(`Farbe ${name} fehlt in backend.css`);
    }

    return value;
}

function mix(top: string, ground: string, alpha: number): string {
    const channel = (hex: string, i: number): number =>
        parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16);

    return `#${[0, 1, 2]
        .map((i) =>
            Math.round(
                alpha * channel(top, i) + (1 - alpha) * channel(ground, i),
            )
                .toString(16)
                .padStart(2, '0'),
        )
        .join('')}`;
}

/** [Vordergrund, Hintergrund] — wo die Kombination vorkommt, steht in barrierefreiheit-tb08.md. */
const text: [string, string][] = [
    ['basalt', 'weiss'],
    ['basalt', 'paper'],
    ['basalt', 'ash-light'],
    ['basalt-light', 'weiss'],
    ['basalt-light', 'paper'],
    ['basalt-light', 'ash-light'],
    ['ash-dark', 'weiss'],
    ['ash-dark', 'paper'],
    ['ash-dark', 'ash-light'],
    ['ash-dark', 'lava/5 auf weiss'],
    ['ash', 'basalt'],
    ['ash-light', 'basalt-light'],
    ['paper', 'basalt'],
    ['paper', 'basalt-light'],
    ['weiss', 'lava-dark'],
    ['lava-dark', 'weiss'],
    ['lava-dark', 'paper'],
    ['lava-dark', 'lava/10 auf weiss'],
    ['lava-dark', 'lava/10 auf paper'],
    ['lava-light', 'basalt'],
    ['success', 'weiss'],
    ['success', 'paper'],
    ['success', 'success/10 auf weiss'],
    ['success', 'success/10 auf paper'],
    ['warning', 'weiss'],
    ['warning', 'warning/10 auf weiss'],
    ['warning', 'warning/10 auf paper'],
    ['danger', 'weiss'],
    ['danger', 'danger/10 auf weiss'],
    ['danger', 'danger/10 auf paper'],
    ['weiss', 'success'],
    ['weiss', 'warning'],
    ['weiss', 'danger'],
];

const ui: [string, string][] = [
    ['ash-dark', 'weiss'],
    ['ash-dark', 'paper'],
    ['lava-dark', 'weiss'],
    ['lava-dark', 'paper'],
    ['lava-light', 'basalt'],
    ['lava-light', 'basalt-light'],
    ['lava', 'weiss'],
    ['success', 'weiss'],
];

describe('Kontraste des Backends', () => {
    it.each(text)('Text %s auf %s mindestens 4,5:1', (fg, bg) => {
        expect(contrast(color(fg), color(bg))).toBeGreaterThanOrEqual(4.5);
    });

    it.each(ui)(
        'Rand, Fokus oder Schalter %s auf %s mindestens 3:1',
        (fg, bg) => {
            expect(contrast(color(fg), color(bg))).toBeGreaterThanOrEqual(3);
        },
    );

    it('Asche allein reicht auf hellem Grund nicht für Text — dafür gibt es ash-dark', () => {
        expect(contrast(color('ash'), color('weiss'))).toBeLessThan(4.5);
        expect(contrast(color('ash'), color('basalt-light'))).toBeLessThan(4.5);
    });
});
