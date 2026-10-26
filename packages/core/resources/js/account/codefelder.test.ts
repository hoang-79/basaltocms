import { afterEach, describe, expect, it, vi } from 'vitest';
import { enhanceCodeForm, startResendCountdown } from './codefelder';

function codeForm(invalid = false): HTMLFormElement {
    document.body.innerHTML = `<form data-basalto-code data-ziffer-text="Ziffer :n von :total" action="/account/code" method="post">
        <label for="konto-code">Code aus der Mail</label>
        <input type="text" id="konto-code" name="code" required inputmode="numeric" maxlength="6" aria-describedby="konto-code-hilfe"${invalid ? ' aria-invalid="true"' : ''}>
        <p id="konto-code-hilfe">Sechs Ziffern.</p>
        <button type="submit" data-code-senden>Anmelden</button></form>`;

    return document.querySelector('form')!;
}

function boxes(): HTMLInputElement[] {
    return Array.from(
        document.querySelectorAll<HTMLInputElement>('.basalto-code input'),
    );
}

function type(box: HTMLInputElement, value: string): void {
    box.value = value;
    box.dispatchEvent(new Event('input'));
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('Code in Ziffernfeldern auf der Website', () => {
    it('macht aus dem einen Feld sechs, das Label zeigt aufs erste, der Knopf verschwindet', () => {
        const form = codeForm(true);
        enhanceCodeForm(form);
        expect(boxes()).toHaveLength(6);
        expect(boxes()[0].id).toBe('konto-code');
        expect(boxes()[0].autocomplete).toBe('one-time-code');
        expect(boxes()[5].getAttribute('aria-label')).toBe('Ziffer 6 von 6');
        expect(boxes()[0].getAttribute('aria-invalid')).toBe('true');
        const group = document.querySelector('.basalto-code')!;
        expect(group.getAttribute('aria-describedby')).toBe('konto-code-hilfe');
        expect(group.getAttribute('aria-labelledby')).toBe(
            document.querySelector('label')!.id,
        );
        const original =
            form.querySelector<HTMLInputElement>('input[name="code"]')!;
        expect([original.type, original.required]).toEqual(['hidden', false]);
        expect(
            form.querySelector<HTMLButtonElement>('[data-code-senden]')!.hidden,
        ).toBe(true);
        expect(document.activeElement).toBe(boxes()[0]);
        enhanceCodeForm(form);
        expect(boxes()).toHaveLength(6);
    });

    it('sendet von selbst, sobald alle Ziffern da sind — getippt oder eingefügt', () => {
        const form = codeForm();
        const sent = vi.fn((event: Event) => event.preventDefault());
        form.addEventListener('submit', sent);
        enhanceCodeForm(form);
        for (const [i, d] of ['1', '2', '3', '4', '5'].entries()) {
            type(boxes()[i], d);
        }
        expect(sent).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(boxes()[5]);
        type(boxes()[5], '6');
        expect(sent).toHaveBeenCalledTimes(1);
        expect(
            form.querySelector<HTMLInputElement>('input[name="code"]')!.value,
        ).toBe('123456');

        const second = codeForm();
        const sentSecond = vi.fn((event: Event) => event.preventDefault());
        second.addEventListener('submit', sentSecond);
        enhanceCodeForm(second);
        const paste = new Event('paste', {
            cancelable: true,
        }) as ClipboardEvent;
        Object.defineProperty(paste, 'clipboardData', {
            value: { getData: () => '987 654' },
        });
        boxes()[2].dispatchEvent(paste);
        expect(
            boxes()
                .map((b) => b.value)
                .join(''),
        ).toBe('987654');
        expect(sentSecond).toHaveBeenCalledTimes(1);
    });

    it('Rücktaste löscht und springt zurück', () => {
        const form = codeForm();
        enhanceCodeForm(form);
        type(boxes()[0], '4');
        type(boxes()[1], '2');
        boxes()[2].dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Backspace',
                cancelable: true,
            }),
        );
        expect(
            boxes()
                .map((b) => b.value)
                .slice(0, 2),
        ).toEqual(['4', '']);
        expect(document.activeElement).toBe(boxes()[1]);
    });
});

describe('Code erneut senden', () => {
    it('zählt die Wartezeit herunter und gibt den Knopf dann frei', () => {
        vi.useFakeTimers();
        document.body.innerHTML = `<form data-basalto-erneut data-warten="2" data-text-eins="Neuer Code in :count Sekunde" data-text-mehr="Neuer Code in :count Sekunden"><button type="submit">Code erneut senden</button></form>`;
        startResendCountdown(document.querySelector('form')!);
        const button = document.querySelector('button')!;
        expect([button.disabled, button.textContent]).toEqual([
            true,
            'Neuer Code in 2 Sekunden',
        ]);
        vi.advanceTimersByTime(1000);
        expect(button.textContent).toBe('Neuer Code in 1 Sekunde');
        vi.advanceTimersByTime(1000);
        expect([button.disabled, button.textContent]).toEqual([
            false,
            'Code erneut senden',
        ]);
    });

    it('ohne Wartezeit sofort klickbar', () => {
        document.body.innerHTML = `<form data-basalto-erneut data-warten="0"><button type="submit">Code erneut senden</button></form>`;
        startResendCountdown(document.querySelector('form')!);
        expect(document.querySelector('button')!.disabled).toBe(false);
    });
});
