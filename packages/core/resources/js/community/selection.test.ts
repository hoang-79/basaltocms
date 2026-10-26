import { describe, expect, it } from 'vitest';
import { enhanceSelection, selectionText } from './selection';

describe('Auswahl der Moderation', () => {
    it('setzt die Zahl in den Text', () => {
        expect(selectionText(':count ausgewählt', 3)).toBe('3 ausgewählt');
    });

    it('zeigt die Leiste erst, wenn etwas angekreuzt ist, und zählt mit', () => {
        document.body.innerHTML = `
            <input type="checkbox" form="auswahl" value="1">
            <input type="checkbox" form="auswahl" value="2">
            <input type="checkbox" form="andere" value="3" checked>
            <form id="auswahl" data-insel="auswahl" data-insel-text=":count ausgewählt"><p data-auswahl-zahl>Ausgewählte Themen</p></form>`;
        const form = document.getElementById('auswahl') as HTMLFormElement;
        enhanceSelection(form);
        expect(form.hidden).toBe(true);
        const first =
            document.querySelector<HTMLInputElement>('input[value="1"]')!;
        first.checked = true;
        first.dispatchEvent(new Event('change'));
        expect(form.hidden).toBe(false);
        expect(form.querySelector('[data-auswahl-zahl]')?.textContent).toBe(
            '1 ausgewählt',
        );
    });
});
