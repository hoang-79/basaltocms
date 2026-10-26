import { fillDigits, typedDigit } from '../codefill';

/**
 * Code in Ziffernfeldern auf der Website (TB 72): Aus dem einen Feld `code` des Formulars `data-basalto-code` werden
 * sechs Felder. Tippen springt weiter, Einfügen oder das Ausfüllen durch den Browser verteilt den Code, sind alle
 * Ziffern da, sendet das Formular von selbst — der Knopf `data-code-senden` verschwindet. Ohne Skript bleibt das eine
 * Feld mit Knopf.
 */
export function enhanceCodeForm(form: HTMLFormElement, length = 6): void {
    const original = form.querySelector<HTMLInputElement>('input[name="code"]');
    if (!original || form.dataset.codeFelder === '1') {
        return;
    }
    form.dataset.codeFelder = '1';
    const id = original.id || 'code';
    const label = form.querySelector<HTMLLabelElement>(`label[for="${id}"]`);
    const describedby = original.getAttribute('aria-describedby');
    const invalid = original.getAttribute('aria-invalid') === 'true';
    const digitText = form.dataset.zifferText ?? 'Ziffer :n von :total';

    const group = document.createElement('div');
    group.className = 'basalto-code';
    group.setAttribute('role', 'group');
    if (label) {
        label.id ||= `${id}-label`;
        group.setAttribute('aria-labelledby', label.id);
    }
    if (describedby) {
        group.setAttribute('aria-describedby', describedby);
    }
    const boxes = Array.from({ length }, (_, i) => {
        const box = document.createElement('input');
        box.type = 'text';
        box.inputMode = 'numeric';
        box.autocomplete = i === 0 ? 'one-time-code' : 'off';
        box.maxLength = length;
        box.setAttribute(
            'aria-label',
            digitText
                .replace(':n', String(i + 1))
                .replace(':total', String(length)),
        );
        if (invalid) {
            box.setAttribute('aria-invalid', 'true');
        }
        return box;
    });
    // Das Label zeigt aufs erste Feld; das Original trägt nur noch den Wert
    original.id = `${id}-wert`;
    boxes[0].id = id;
    original.type = 'hidden';
    original.removeAttribute('required');
    group.append(...boxes);
    original.after(group);
    const submit = form.querySelector<HTMLButtonElement>('[data-code-senden]');
    if (submit) {
        submit.hidden = true;
    }

    let digits: string[] = Array.from({ length }, () => '');
    const render = (): void => {
        boxes.forEach((box, i) => (box.value = digits[i]));
        original.value = digits.join('');
    };
    const focus = (index: number): void => {
        const box = boxes[Math.max(0, Math.min(index, length - 1))];
        box.focus();
        box.select();
    };
    const apply = (index: number, raw: string): void => {
        const result = fillDigits(digits, index, raw);
        if (result === null) {
            render();
            return;
        }
        digits = result.digits;
        render();
        if (!result.complete) {
            focus(result.next);
            return;
        }
        if (form.dataset.sendet !== '1') {
            form.dataset.sendet = '1';
            boxes.forEach((box) => {
                box.readOnly = true;
                box.blur();
            });
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    };
    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            const typed = typedDigit(digits[i], box.value);
            box.value = digits[i];
            apply(i, typed);
        });
        box.addEventListener('paste', (event) => {
            event.preventDefault();
            apply(i, event.clipboardData?.getData('text') ?? '');
        });
        box.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace') {
                event.preventDefault();
                const at = digits[i] === '' ? i - 1 : i;
                if (at >= 0) {
                    digits[at] = '';
                    render();
                    focus(at);
                }
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                focus(i - 1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                focus(i + 1);
            }
        });
        box.addEventListener('focus', () => box.select());
    });
    focus(0);
}

/**
 * «Code erneut senden» (TB 72): Der Knopf des Formulars `data-basalto-erneut` zählt die Wartezeit herunter
 * (`data-warten` Sekunden, Texte `data-text-eins` und `data-text-mehr` mit `:count`) und wird danach klickbar.
 * Ohne Skript ist er sofort klickbar — der Server prüft die Wartezeit ohnehin.
 */
export function startResendCountdown(form: HTMLFormElement): void {
    const button = form.querySelector<HTMLButtonElement>('button');
    if (!button || form.dataset.countdown === '1') {
        return;
    }
    form.dataset.countdown = '1';
    const ready = button.textContent ?? '';
    let remaining = Math.max(0, Number(form.dataset.warten ?? '0') || 0);
    const update = (): void => {
        button.disabled = remaining > 0;
        button.textContent =
            remaining > 0
                ? (remaining === 1
                      ? (form.dataset.textEins ?? '')
                      : (form.dataset.textMehr ?? '')
                  ).replace(':count', String(remaining))
                : ready;
    };
    update();
    if (remaining > 0) {
        const timer = window.setInterval(() => {
            remaining -= 1;
            update();
            if (remaining <= 0) {
                window.clearInterval(timer);
            }
        }, 1000);
    }
}
