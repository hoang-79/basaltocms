import { inspectFile, type PackageResult } from './paket';

/**
 * Formular in Schritten im Dialog (CR-016, TB 86) — zuerst für das Einreichen im Marktplatz: `form[data-schritte]` mit
 * `fieldset[data-schritt]` je Schritt. Mit Skript öffnet die Klappe darum (`details`) einen Dialog mit Fortschritt,
 * «Zurück»/«Weiter» (prüft die Felder des Schritts) und erst im letzten Schritt «Einreichen»; Verweise auf die Klappe
 * (`#einreichung`) öffnen ihn auch. Ohne Skript bleibt alles ein Formular in der Klappe. Kam der Server mit Fehlern
 * zurück, öffnet der Dialog beim ersten Schritt mit Fehler. Texte kommen aus `data-text-*` am Formular.
 *
 * Dazu für Pakete: Ablegen per Ziehen auf das Dateifeld (`[data-zip]`), Art, Name und Version sofort in
 * `[data-zip-vorschau]`, und die Zusammenfassung `[data-zusammenfassung]` im letzten Schritt.
 */
export type StepDialog = {
    dialog: HTMLDialogElement;
    open: (step?: number) => void;
    show: (step: number) => void;
    current: () => number;
};

function texts(
    form: HTMLFormElement,
): (key: string, fallback: string, replace?: Record<string, string>) => string {
    return (key, fallback, replace = {}) => {
        let value = form.getAttribute(`data-text-${key}`) ?? fallback;
        for (const [name, text] of Object.entries(replace)) {
            value = value.split(`:${name}`).join(text);
        }

        return value;
    };
}

function visible(el: HTMLElement): boolean {
    if (el.closest('[hidden]')) {
        return false;
    }
    const check = (el as HTMLElement & { checkVisibility?: () => boolean })
        .checkVisibility;

    return typeof check === 'function' ? check.call(el) : true;
}

function fields(
    step: HTMLElement,
): (HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement)[] {
    return Array.from(
        step.querySelectorAll<
            HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
        >('input, select, textarea'),
    ).filter((el) => !el.disabled && el.type !== 'hidden' && visible(el));
}

/** «Verkaufen» macht den Preis zur Pflicht; «Kostenlos» nicht. */
function offer(form: HTMLFormElement): void {
    const price = form.querySelector<HTMLInputElement>('input[name="price"]');
    const sync = (): void => {
        const sale = form.querySelector<HTMLInputElement>(
            'input[name="angebot"][value="verkauf"]',
        );
        if (price && sale) {
            price.required = sale.checked;
        }
    };
    form.querySelectorAll<HTMLInputElement>('input[name="angebot"]').forEach(
        (radio) => radio.addEventListener('change', sync),
    );
    sync();
}

/** Ablegen per Ziehen und die Vorschau aus dem ZIP. */
function packageField(
    form: HTMLFormElement,
    say: ReturnType<typeof texts>,
    onResult: (r: PackageResult | null, file: File | null) => void,
): void {
    const input = form.querySelector<HTMLInputElement>(
        'input[type="file"][data-zip]',
    );
    const preview = form.querySelector<HTMLElement>('[data-zip-vorschau]');
    if (!input) {
        return;
    }
    const zone = document.createElement('div');
    zone.className = 'schritte-ablage';
    zone.innerHTML = `<span class="schritte-ablage__text"></span>`;
    zone.querySelector('span')!.textContent = say(
        'ablegen',
        'ZIP-Datei hierher ziehen oder auswählen',
    );
    input.parentElement?.insertBefore(zone, input);
    zone.append(input);
    for (const type of ['dragenter', 'dragover']) {
        zone.addEventListener(type, (event) => {
            event.preventDefault();
            zone.classList.add('ist-drueber');
        });
    }
    for (const type of ['dragleave', 'drop']) {
        zone.addEventListener(type, () => zone.classList.remove('ist-drueber'));
    }
    zone.addEventListener('drop', (event) => {
        event.preventDefault();
        const files = (event as DragEvent).dataTransfer?.files;
        if (files && files.length > 0) {
            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    input.addEventListener('change', async () => {
        const file = input.files?.[0] ?? null;
        if (!preview) {
            return;
        }
        if (!file) {
            preview.hidden = true;
            onResult(null, null);

            return;
        }
        const result = await inspectFile(file);
        const size = `${(file.size / 1024 / 1024).toLocaleString(document.documentElement.lang || 'de', { maximumFractionDigits: 1 })} MB`;
        preview.hidden = false;
        preview.classList.toggle('ist-fehler', !result.ok);
        if (result.ok) {
            const { info } = result;
            preview.textContent = `${info.kind === 'theme' ? say('theme', 'Theme «:name» :version', { name: info.label ?? info.name, version: info.version }) : say('plugin', 'Plugin :name :version', { name: info.name, version: info.version })} · ${size}`;
            const summary = form.querySelector<HTMLInputElement>(
                'input[name="summary"]',
            );
            if (summary && info.description && summary.value === '') {
                summary.placeholder = info.description;
            }
        } else {
            preview.textContent =
                result.reason === 'zip'
                    ? say('kein-zip', 'Das ist keine ZIP-Datei.')
                    : say(
                          'kein-paket',
                          'Kein Theme- oder Plugin-Paket erkannt.',
                      );
        }
        onResult(result, file);
    });
}

export function enhanceStepForm(form: HTMLFormElement): StepDialog | null {
    const steps = Array.from(
        form.querySelectorAll<HTMLFieldSetElement>('fieldset[data-schritt]'),
    );
    if (
        steps.length < 2 ||
        form.dataset.schritteAktiv === '1' ||
        typeof HTMLDialogElement === 'undefined'
    ) {
        return null;
    }
    form.dataset.schritteAktiv = '1';
    const say = texts(form);
    const holder = form.closest<HTMLDetailsElement>('details');
    const id = `${holder?.id || 'schritte'}-dialog`;
    let current = 0;
    let dirty = false;
    let packageText = '';

    const dialog = document.createElement('dialog');
    dialog.className = 'schritte-dialog';
    dialog.setAttribute('aria-labelledby', `${id}-titel`);
    const head = document.createElement('div');
    head.className = 'schritte-kopf';
    const title = document.createElement('h2');
    title.id = `${id}-titel`;
    title.textContent =
        holder?.dataset.titel ??
        holder?.querySelector('summary')?.textContent?.trim() ??
        '';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'schritte-zu';
    close.setAttribute('aria-label', say('schliessen', 'Schliessen'));
    close.textContent = '×';
    head.append(title, close);
    const progress = document.createElement('ol');
    progress.className = 'schritte-fortschritt';
    steps.forEach((step, i) => {
        const item = document.createElement('li');
        const legend = step.querySelector('legend')?.textContent ?? '';
        item.textContent = legend.replace(/^\s*\d+\s*·\s*/, '');
        item.dataset.nummer = String(i + 1);
        progress.append(item);
    });
    const status = document.createElement('p');
    status.className = 'schritte-stand';
    status.setAttribute('aria-live', 'polite');
    dialog.append(head, progress, status);
    (holder ?? form).insertAdjacentElement('afterend', dialog);
    dialog.append(form);

    const submit = form.querySelector<HTMLButtonElement>(
        'button[type="submit"]',
    );
    const nav = document.createElement('div');
    nav.className = 'schritte-knoepfe';
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'konto-knopf';
    back.textContent = say('zurueck', 'Zurück');
    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'konto-knopf konto-knopf--lava';
    next.textContent = say('weiter', 'Weiter');
    nav.append(back, next);
    if (submit) {
        nav.append(submit);
    }
    form.append(nav);

    const summarize = (): void => {
        const list = form.querySelector<HTMLElement>('[data-zusammenfassung]');
        if (!list) {
            return;
        }
        const sale =
            form.querySelector<HTMLInputElement>(
                'input[name="angebot"][value="verkauf"]',
            )?.checked ?? false;
        const license = form.querySelector<HTMLSelectElement>(
            'select[name="license"]',
        );
        const price =
            form
                .querySelector<HTMLInputElement>('input[name="price"]')
                ?.value.trim() ?? '';
        const currency =
            form.querySelector<HTMLSelectElement>('select[name="currency"]')
                ?.value ?? '';
        const summary = form.querySelector<HTMLInputElement>(
            'input[name="summary"]',
        );
        const file =
            form.querySelector<HTMLInputElement>('input[type="file"]')
                ?.files?.[0]?.name ?? '';
        const rows: [string, string][] = [
            [say('zeile-paket', 'Paket'), packageText || file],
            [
                say('zeile-kurz', 'Kurzbeschreibung'),
                summary?.value.trim() ||
                    summary?.placeholder ||
                    say('aus-paket', 'aus dem Paket'),
            ],
            [
                say('zeile-angebot', 'Angebot'),
                sale
                    ? `${say('verkaufen', 'Verkaufen')} · ${currency} ${price}`
                    : `${say('kostenlos', 'Kostenlos')} · ${license?.selectedOptions[0]?.textContent ?? ''}`,
            ],
        ];
        list.replaceChildren(
            ...rows.map(([label, value]) => {
                const row = document.createElement('div');
                const dt = document.createElement('dt');
                const dd = document.createElement('dd');
                dt.textContent = label;
                dd.textContent = value;
                row.append(dt, dd);

                return row;
            }),
        );
        list.hidden = false;
    };

    const show = (step: number): void => {
        current = Math.max(0, Math.min(steps.length - 1, step));
        steps.forEach((s, i) => {
            s.hidden = i !== current;
        });
        Array.from(progress.children).forEach((item, i) => {
            item.classList.toggle('ist-fertig', i < current);
            if (i === current) {
                item.setAttribute('aria-current', 'step');
            } else {
                item.removeAttribute('aria-current');
            }
        });
        status.textContent = say('schritt', 'Schritt :n von :total', {
            n: String(current + 1),
            total: String(steps.length),
        });
        back.hidden = current === 0;
        const last = current === steps.length - 1;
        next.hidden = last;
        if (submit) {
            submit.hidden = !last;
        }
        if (last) {
            summarize();
        }
    };

    const valid = (): boolean => {
        for (const field of fields(steps[current])) {
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();

                return false;
            }
        }

        return true;
    };

    const open = (step = 0): void => {
        show(step);
        if (!dialog.open) {
            dialog.showModal();
        }
        fields(steps[current])[0]?.focus();
    };

    const leave = (): boolean =>
        !dirty || window.confirm(say('verwerfen', 'Eingaben verwerfen?'));

    back.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => {
        if (valid()) {
            show(current + 1);
            fields(steps[current])[0]?.focus();
        }
    });
    close.addEventListener('click', () => {
        if (leave()) {
            dialog.close();
        }
    });
    dialog.addEventListener('cancel', (event) => {
        if (!leave()) {
            event.preventDefault();
        }
    });
    form.addEventListener('input', () => {
        dirty = true;
    });
    form.addEventListener('submit', (event) => {
        if (current < steps.length - 1) {
            event.preventDefault();
            next.click();
        }
    });
    form.addEventListener('keydown', (event) => {
        const target = event.target as HTMLElement;
        if (
            event.key === 'Enter' &&
            target.tagName === 'INPUT' &&
            (target as HTMLInputElement).type !== 'submit' &&
            current < steps.length - 1
        ) {
            event.preventDefault();
            next.click();
        }
    });
    offer(form);
    packageField(form, say, (result) => {
        packageText = result?.ok
            ? (form.querySelector<HTMLElement>('[data-zip-vorschau]')
                  ?.textContent ?? '')
            : '';
    });

    // Die Klappe wird zum Auslöser; Verweise auf sie öffnen den Dialog
    if (holder) {
        const summary = holder.querySelector('summary');
        summary?.addEventListener('click', (event) => {
            event.preventDefault();
            open();
        });
        document
            .querySelectorAll<HTMLAnchorElement>(`a[href="#${holder.id}"]`)
            .forEach((link) =>
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    open();
                }),
            );
        if (holder.open) {
            // Vom Server geöffnet (neue Version, Fehler): zum ersten Schritt mit Fehler
            const marker = '.has-error, [aria-invalid="true"], .form-error';
            const failed = steps.findIndex(
                (s) => s.matches(marker) || s.querySelector(marker) !== null,
            );
            holder.open = false;
            open(failed < 0 ? 0 : failed);
        }
    }
    show(current);

    return { dialog, open, show, current: () => current };
}
