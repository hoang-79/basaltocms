import { globSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/** Vitest läuft im Stamm des Repos (vite.config.ts). */
const root = `${process.cwd()}/`;

/** Die gesperrten Fieldsets einer Vorlage mit ihrem Inhalt (verschachtelte mitgezählt) und der Zeile, wo sie beginnen. */
function disabledFieldsets(source: string): { line: number; body: string }[] {
    const found: { line: number; body: string }[] = [];
    const open = /<fieldset\b[^>]*?\s:?disabled\b[^>]*>/g;
    for (const match of source.matchAll(open)) {
        const start = match.index ?? 0;
        let depth = 0;
        const tags = /<\/?fieldset\b/g;
        tags.lastIndex = start;
        for (const tag of source.matchAll(tags)) {
            if ((tag.index ?? 0) < start) {
                continue;
            }
            depth += tag[0].startsWith('</') ? -1 : 1;
            if (depth === 0) {
                found.push({
                    line: source.slice(0, start).split('\n').length,
                    body: source.slice(start, tag.index),
                });
                break;
            }
        }
    }

    return found;
}

/**
 * Ein gesperrtes `<fieldset disabled>` sperrt jedes Bedienelement darin — auch Knöpfe, die nur auf- und zuklappen.
 * Typen und Formulare aus dem Theme liessen sich so nicht ansehen («Ansehen», «Weitere Möglichkeiten»; TB 89, K-15):
 * Knöpfe gehören vor oder hinter das gesperrte Fieldset.
 */
describe('Nur lesen', () => {
    it('ein gesperrtes Fieldset enthält keine Knöpfe', () => {
        const files = globSync(
            [
                'packages/core/resources/js/**/*.vue',
                'modules/*/resources/js/**/*.vue',
            ],
            { cwd: root },
        );
        expect(files.length).toBeGreaterThan(50);
        const offenders = files.flatMap((file) =>
            disabledFieldsets(readFileSync(root + file, 'utf8'))
                .filter(({ body }) => /<button\b/.test(body))
                .map(({ line }) => `${file}:${line}`),
        );
        expect(offenders).toEqual([]);
    });

    it('findet einen Knopf im gesperrten Fieldset', () => {
        const source =
            '<p/>\n<fieldset :disabled="readOnly"><fieldset><input></fieldset><button>Ansehen</button></fieldset>';
        expect(disabledFieldsets(source)).toEqual([
            {
                line: 2,
                body: '<fieldset :disabled="readOnly"><fieldset><input></fieldset><button>Ansehen</button>',
            },
        ]);
    });
});
