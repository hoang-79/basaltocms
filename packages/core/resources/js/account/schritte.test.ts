import { describe, expect, it } from 'vitest';
import { enhanceStepForm } from './schritte';

function markup(open = false, errorInStep = -1): HTMLFormElement {
    const error = (i: number) =>
        i === errorInStep ? ' class="has-error"' : '';
    document.body.innerHTML = `<div class="konto"><a href="#einreichung" id="werkzeug">Neue Einreichung</a>
        <details id="einreichung" data-titel="Neue Einreichung"${open ? ' open' : ''}><summary>Neue Einreichung</summary>
        <form data-schritte data-text-schritt="Schritt :n von :total" data-text-weiter="Weiter" data-text-zurueck="Zurück">
            <fieldset data-schritt="paket"${error(0)}><legend>1 · Paket</legend><input type="text" name="name" required></fieldset>
            <fieldset data-schritt="angaben"${error(1)}><legend>2 · Angaben</legend><input type="text" name="summary"></fieldset>
            <fieldset data-schritt="angebot"${error(2)}><legend>3 · Angebot</legend>
                <input type="radio" name="angebot" value="kostenlos" checked><input type="radio" name="angebot" value="verkauf">
                <input type="text" name="price"></fieldset>
            <fieldset data-schritt="pruefen"${error(3)}><legend>4 · Prüfen</legend><dl data-zusammenfassung hidden></dl></fieldset>
            <button type="submit">Einreichen</button>
        </form></details></div>`;

    return document.querySelector('form')!;
}

describe('Formular in Schritten', () => {
    it('öffnet als Dialog mit Fortschritt und prüft den Schritt vor «Weiter»', () => {
        const form = markup();
        const steps = enhanceStepForm(form)!;
        expect(steps).not.toBeNull();
        expect(steps.dialog.contains(form)).toBe(true);
        expect(
            Array.from(
                steps.dialog.querySelectorAll('.schritte-fortschritt li'),
            ).map((li) => li.textContent),
        ).toEqual(['Paket', 'Angaben', 'Angebot', 'Prüfen']);

        document.querySelector<HTMLElement>('#einreichung summary')!.click();
        expect(steps.dialog.open).toBe(true);
        expect(steps.dialog.querySelector('.schritte-stand')!.textContent).toBe(
            'Schritt 1 von 4',
        );
        const [back, next] = Array.from(
            steps.dialog.querySelectorAll<HTMLButtonElement>(
                '.schritte-knoepfe button[type="button"]',
            ),
        );
        const submit = form.querySelector<HTMLButtonElement>(
            'button[type="submit"]',
        )!;
        expect([back.hidden, submit.hidden]).toEqual([true, true]);

        // Pflichtfeld leer → bleibt bei Schritt 1
        next.click();
        expect(steps.current()).toBe(0);
        form.querySelector<HTMLInputElement>('input[name="name"]')!.value =
            'tavola';
        next.click();
        next.click();
        expect(steps.current()).toBe(2);

        // Verkaufen macht den Preis zur Pflicht
        const sale = form.querySelector<HTMLInputElement>(
            'input[value="verkauf"]',
        )!;
        sale.checked = true;
        sale.dispatchEvent(new Event('change'));
        expect(
            form.querySelector<HTMLInputElement>('input[name="price"]')!
                .required,
        ).toBe(true);
        form.querySelector<HTMLInputElement>('input[name="price"]')!.value =
            '99';
        next.click();
        expect([steps.current(), submit.hidden, next.hidden]).toEqual([
            3,
            false,
            true,
        ]);
        expect(
            form.querySelector('[data-zusammenfassung]')!.textContent,
        ).toContain('99');
    });

    it('öffnet nach Fehlern vom Server beim Schritt mit dem Fehler, Verweise öffnen ihn auch', () => {
        const form = markup(true, 2);
        const steps = enhanceStepForm(form)!;
        expect([steps.dialog.open, steps.current()]).toEqual([true, 2]);
        steps.dialog.close();
        document.getElementById('werkzeug')!.click();
        expect([steps.dialog.open, steps.current()]).toEqual([true, 0]);
    });

    it('lässt Formulare mit einem Schritt in Ruhe', () => {
        document.body.innerHTML =
            '<form data-schritte><fieldset data-schritt="a"><legend>A</legend></fieldset></form>';
        expect(enhanceStepForm(document.querySelector('form')!)).toBeNull();
    });
});
