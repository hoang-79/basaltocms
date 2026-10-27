import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AUTOSAVE_HEADER,
    enhanceAutosave,
    enhanceDraft,
    feldname,
    speicherart,
} from './speichern';

type Antwort = { status: number; body: Record<string, unknown> };

function formular(): HTMLFormElement {
    document.body.innerHTML = `
        <form action="/account/profil" method="post" data-autosave>
            <input type="hidden" name="_token" value="t">
            <div class="form-field"><label for="name">Name</label><input id="name" name="name" value="Nina"></div>
            <div class="form-field"><label for="mail">E-Mail</label><input id="mail" type="email" name="email" value="nina@example.ch"></div>
            <div class="form-field"><label for="mails">Mails</label><select id="mails" name="mails"><option value="sofort" selected>sofort</option><option value="nie">nie</option></select></div>
            <button type="submit" data-autosave-knopf>Speichern</button>
        </form>`;

    return document.querySelector('form')!;
}

function eingabe(
    feld: HTMLInputElement | HTMLSelectElement,
    wert: string,
    art: 'input' | 'change' = 'input',
): void {
    feld.value = wert;
    feld.dispatchEvent(new Event(art, { bubbles: true }));
}

describe('Autosave im Kundenkonto (CR-013)', () => {
    let gesendet: { daten: FormData; headers: Record<string, string> }[] = [];
    let antworten: Antwort[] = [];

    beforeEach(() => {
        vi.useFakeTimers();
        gesendet = [];
        antworten = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(async (_url: string, init: RequestInit) => {
                gesendet.push({
                    daten: init.body as FormData,
                    headers: init.headers as Record<string, string>,
                });
                const a = antworten.shift() ?? {
                    status: 200,
                    body: { message: 'Gespeichert' },
                };

                return {
                    ok: a.status < 300,
                    status: a.status,
                    json: async () => a.body,
                };
            }),
        );
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('versteckt den Knopf und speichert Text nach der Pause', async () => {
        const form = formular();
        enhanceAutosave(form);
        expect(
            form.querySelector<HTMLButtonElement>('[data-autosave-knopf]')!
                .hidden,
        ).toBe(true);
        eingabe(form.querySelector('#name')!, 'Nina D.');
        await vi.advanceTimersByTimeAsync(799);
        expect(gesendet).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(gesendet).toHaveLength(1);
        expect(gesendet[0]!.daten.get('name')).toBe('Nina D.');
        expect(gesendet[0]!.headers[AUTOSAVE_HEADER]).toBe('1');
        expect(form.querySelector('.autosave-status')!.textContent).toMatch(
            /^Gespeichert · /,
        );
    });

    it('Auswahlen sofort, E-Mail erst mit Enter — bis dahin mit dem alten Wert', async () => {
        const form = formular();
        enhanceAutosave(form);
        eingabe(
            form.querySelector<HTMLInputElement>('#mail')!,
            'neu@example.ch',
        );
        eingabe(
            form.querySelector<HTMLSelectElement>('#mails')!,
            'nie',
            'change',
        );
        await vi.advanceTimersByTimeAsync(0);
        expect(gesendet).toHaveLength(1);
        expect(gesendet[0]!.daten.get('mails')).toBe('nie');
        expect(gesendet[0]!.daten.get('email')).toBe('nina@example.ch');

        form.querySelector('#mail')!.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await vi.advanceTimersByTimeAsync(0);
        expect(gesendet).toHaveLength(2);
        expect(gesendet[1]!.daten.get('email')).toBe('neu@example.ch');
    });

    it('zeigt Fehler am Feld, und ein abgelehntes Feld blockiert die übrigen nicht', async () => {
        const form = formular();
        enhanceAutosave(form);
        antworten.push({
            status: 422,
            body: { errors: { name: ['Dieser Name ist vergeben.'] } },
        });
        eingabe(form.querySelector('#name')!, 'Admin');
        await vi.advanceTimersByTimeAsync(800);
        expect(form.querySelector('.form-error')!.textContent).toBe(
            'Dieser Name ist vergeben.',
        );
        expect(form.querySelector('#name')!.getAttribute('aria-invalid')).toBe(
            'true',
        );

        eingabe(
            form.querySelector<HTMLSelectElement>('#mails')!,
            'nie',
            'change',
        );
        await vi.advanceTimersByTimeAsync(0);
        expect(gesendet[1]!.daten.get('name')).toBe('Nina');
        expect(gesendet[1]!.daten.get('mails')).toBe('nie');
        expect(form.querySelector('.form-error')!.textContent).toBe(
            'Dieser Name ist vergeben.',
        );
    });

    it('nach abgelaufener Sitzung kommt der Knopf zurück', async () => {
        const form = formular();
        enhanceAutosave(form);
        antworten.push({ status: 419, body: { message: 'Page Expired' } });
        eingabe(
            form.querySelector<HTMLSelectElement>('#mails')!,
            'nie',
            'change',
        );
        await vi.advanceTimersByTimeAsync(0);
        expect(
            form.querySelector<HTMLButtonElement>('[data-autosave-knopf]')!
                .hidden,
        ).toBe(false);
    });

    it('Speicherart nach Typ und Fehlernamen des Servers', () => {
        const form = formular();
        expect(speicherart(form.querySelector('#name')!)).toBe('pause');
        expect(speicherart(form.querySelector('#mail')!)).toBe('verlassen');
        expect(speicherart(form.querySelector('#mails')!)).toBe('sofort');
        expect(feldname('felder.website')).toContain('felder[website]');
    });

    it('ein Entwurf im Browser übersteht das Neuladen, gilt nur für dieselbe Fassung und fällt beim Senden weg', async () => {
        window.localStorage.clear();
        const seite = (alt: string): HTMLTextAreaElement => {
            document.body.innerHTML = `<form><textarea data-entwurf="antwort:7">${alt}</textarea><button>Senden</button></form>`;
            const feld = document.querySelector('textarea')!;
            enhanceDraft(feld);

            return feld;
        };
        const feld = seite('Danke!');
        feld.value = 'Danke für die Bewertung, wir';
        feld.dispatchEvent(new Event('input'));
        await vi.advanceTimersByTimeAsync(400);

        expect(seite('Danke!').value).toBe('Danke für die Bewertung, wir');
        // Die Antwort wurde inzwischen anderswo geändert: der alte Entwurf überschreibt sie nicht
        expect(seite('Danke, Anna!').value).toBe('Danke, Anna!');

        const wieder = seite('Danke!');
        wieder.form!.dispatchEvent(new Event('submit'));
        expect(
            window.localStorage.getItem('basalto-entwurf:antwort:7'),
        ).toBeNull();
    });
});
