/**
 * Autosave für Formulare des Kundenkontos und der Community (CR-013, TB 73) — fortschreitend: Ohne Skript sendet das
 * Formular wie bisher mit seinem Knopf; mit Skript speichert es selbst per `fetch` (JSON-Antwort), der Knopf
 * (`data-autosave-knopf`) verschwindet, Fehler stehen am Feld, eine Statuszeile sagt «Gespeichert · 09:41».
 *
 * Drei Speicherarten je Feld (`data-autosave="sofort|pause|verlassen"`, sonst nach Typ): Kästchen, Optionen,
 * Auswahlen und Dateien sofort; Text und Zahlen nach einer Pause und beim Verlassen; E-Mail, Passwort und Felder mit
 * `verlassen` erst mit Enter oder beim Verlassen (Esc nimmt zurück). Ein abgelehntes Feld blockiert die übrigen nicht:
 * Bis es neu bearbeitet wird, geht sein zuletzt gespeicherter Wert mit.
 */

export type Speicherart = 'sofort' | 'pause' | 'verlassen';

type Feld = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

export const AUTOSAVE_HEADER = 'X-Basalto-Autosave';

/** Die Speicherart eines Felds: ausdrücklich oder nach Typ. */
export function speicherart(feld: Feld): Speicherart {
    const gesetzt = feld.dataset.autosave;
    if (
        gesetzt === 'sofort' ||
        gesetzt === 'pause' ||
        gesetzt === 'verlassen'
    ) {
        return gesetzt;
    }
    if (feld instanceof HTMLSelectElement) {
        return 'sofort';
    }
    if (feld instanceof HTMLTextAreaElement) {
        return 'pause';
    }
    if (
        [
            'checkbox',
            'radio',
            'file',
            'date',
            'datetime-local',
            'time',
            'color',
            'range',
        ].includes(feld.type)
    ) {
        return 'sofort';
    }
    if (['email', 'password'].includes(feld.type)) {
        return 'verlassen';
    }

    return 'pause';
}

/** Werte eines Formulars je Name (Dateien ausgenommen) — mehrfache Namen («optionen[]») als Liste. */
export function werte(form: HTMLFormElement): Map<string, string[]> {
    const map = new Map<string, string[]>();
    for (const [name, wert] of new FormData(form).entries()) {
        if (typeof wert !== 'string') {
            continue;
        }
        map.set(name, [...(map.get(name) ?? []), wert]);
    }

    return map;
}

function gleich(a: string[] | undefined, b: string[] | undefined): boolean {
    return JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
}

/** Fehlername des Servers («felder.website», «optionen.0») → Feldname im Formular («felder[website]», «optionen[]»). */
export function feldname(schluessel: string): string[] {
    const teile = schluessel.split('.');
    const verschachtelt =
        teile[0] +
        teile
            .slice(1)
            .map((t) => `[${t}]`)
            .join('');

    return [schluessel, verschachtelt, `${teile[0]}[]`];
}

export function enhanceAutosave(form: HTMLFormElement, pause = 800): void {
    if (form.dataset.autosaveAktiv === '1') {
        return;
    }
    form.dataset.autosaveAktiv = '1';
    const knoepfe = Array.from(
        form.querySelectorAll<HTMLElement>('[data-autosave-knopf]'),
    );
    knoepfe.forEach((k) => (k.hidden = true));
    const status =
        form.querySelector<HTMLElement>('[data-autosave-status]') ??
        document.createElement('p');
    if (!status.isConnected) {
        status.className = 'autosave-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        form.append(status);
    }
    const texte = {
        speichert: form.dataset.textSpeichert ?? 'Speichert …',
        gespeichert: form.dataset.textGespeichert ?? 'Gespeichert',
        fehler: form.dataset.textFehler ?? 'Nicht gespeichert',
        wartet:
            form.dataset.textWartet ?? 'Enter oder Feld verlassen speichert',
        neuLaden:
            form.dataset.textNeuLaden ??
            'Nicht gespeichert — bitte laden Sie die Seite neu.',
    };

    let gespeichert = werte(form);
    /** Felder «beim Verlassen», die mitgehen dürfen: Name → Wert */
    const freigegeben = new Map<string, string[]>();
    /** Abgelehnte Felder: Name → abgelehnter Wert */
    const abgelehnt = new Map<string, string[]>();
    const dateienGeaendert = new Set<string>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let laeuft = false;
    let nochmal = false;

    const felder = (): Feld[] =>
        Array.from(form.elements).filter(
            (e): e is Feld =>
                (e instanceof HTMLInputElement ||
                    e instanceof HTMLSelectElement ||
                    e instanceof HTMLTextAreaElement) &&
                e.name !== '' &&
                !(e instanceof HTMLInputElement && e.type === 'hidden'),
        );

    function artVon(name: string): Speicherart {
        const feld = felder().find((f) => f.name === name);

        return feld ? speicherart(feld) : 'sofort';
    }

    /** Was gesendet wird: aktuelle Werte, aber wartende und abgelehnte Felder mit dem gespeicherten Wert. */
    function nutzlast(): { daten: FormData; wartend: string[] } {
        const daten = new FormData(form);
        const aktuell = werte(form);
        const wartend: string[] = [];
        const ersetzen = (name: string): void => {
            daten.delete(name);
            for (const w of gespeichert.get(name) ?? []) {
                daten.append(name, w);
            }
        };
        for (const [name, wert] of aktuell) {
            if (
                artVon(name) === 'verlassen' &&
                !gleich(wert, gespeichert.get(name)) &&
                !gleich(wert, freigegeben.get(name))
            ) {
                ersetzen(name);
                wartend.push(name);
            }
        }
        for (const [name, wert] of abgelehnt) {
            if (gleich(aktuell.get(name), wert)) {
                ersetzen(name);
            }
        }
        // Dateien nur, wenn eine gewählt wurde
        for (const feld of felder()) {
            if (
                feld instanceof HTMLInputElement &&
                feld.type === 'file' &&
                !dateienGeaendert.has(feld.name)
            ) {
                daten.delete(feld.name);
            }
        }

        return { daten, wartend };
    }

    function unterschiede(daten: FormData): boolean {
        if (dateienGeaendert.size > 0) {
            return true;
        }
        const neu = new Map<string, string[]>();
        for (const [name, wert] of daten.entries()) {
            if (typeof wert === 'string') {
                neu.set(name, [...(neu.get(name) ?? []), wert]);
            }
        }
        const namen = new Set([...neu.keys(), ...gespeichert.keys()]);

        return Array.from(namen).some(
            (n) => !gleich(neu.get(n), gespeichert.get(n)),
        );
    }

    function zeige(text: string, art: 'ok' | 'fehler' | 'still'): void {
        status.textContent = text;
        status.dataset.art = art;
    }

    function fehlerLeeren(): void {
        form.querySelectorAll('.form-error[data-autosave-fehler]').forEach(
            (e) => e.remove(),
        );
        form.querySelectorAll(
            '.form-field.has-error[data-autosave-markiert]',
        ).forEach((e) => {
            e.classList.remove('has-error');
            e.removeAttribute('data-autosave-markiert');
        });
        felder().forEach((f) => {
            if (f.dataset.autosaveInvalid === '1') {
                f.removeAttribute('aria-invalid');
                delete f.dataset.autosaveInvalid;
            }
        });
    }

    function fehlerZeigen(fehler: Record<string, string[] | string>): string {
        fehlerLeeren();
        let erste = '';
        for (const [schluessel, meldungen] of Object.entries(fehler)) {
            const meldung = Array.isArray(meldungen)
                ? (meldungen[0] ?? '')
                : meldungen;
            erste ||= meldung;
            const namen = feldname(schluessel);
            const feld = felder().find((f) => namen.includes(f.name));
            if (!feld) {
                continue;
            }
            abgelehnt.set(feld.name, werte(form).get(feld.name) ?? []);
            feld.setAttribute('aria-invalid', 'true');
            feld.dataset.autosaveInvalid = '1';
            const rahmen = feld.closest('.form-field');
            if (rahmen && !rahmen.classList.contains('has-error')) {
                rahmen.classList.add('has-error');
                rahmen.setAttribute('data-autosave-markiert', '1');
            }
            const p = document.createElement('p');
            p.className = 'form-error';
            p.dataset.autosaveFehler = '1';
            p.id = `${feld.id || feld.name}-autosave-fehler`;
            p.textContent = meldung;
            (rahmen ?? feld.parentElement ?? form).append(p);
            const beschrieben = (feld.getAttribute('aria-describedby') ?? '')
                .split(' ')
                .filter(Boolean);
            if (!beschrieben.includes(p.id)) {
                feld.setAttribute(
                    'aria-describedby',
                    [...beschrieben, p.id].join(' '),
                );
            }
        }

        return erste;
    }

    async function speichern(): Promise<void> {
        if (timer) {
            clearTimeout(timer);
            timer = undefined;
        }
        if (laeuft) {
            nochmal = true;

            return;
        }
        const { daten, wartend } = nutzlast();
        if (!unterschiede(daten)) {
            if (wartend.length > 0) {
                zeige(texte.wartet, 'still');
            }

            return;
        }
        laeuft = true;
        zeige(texte.speichert, 'still');
        const gesendet = new Map<string, string[]>();
        for (const [name, wert] of daten.entries()) {
            if (typeof wert === 'string') {
                gesendet.set(name, [...(gesendet.get(name) ?? []), wert]);
            }
        }
        try {
            const antwort = await fetch(form.action, {
                method: 'POST',
                body: daten,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    [AUTOSAVE_HEADER]: '1',
                },
            });
            const json = (await antwort.json().catch(() => ({}))) as {
                message?: string;
                errors?: Record<string, string[]>;
                values?: Record<string, string>;
                reload?: boolean;
            };
            if (antwort.ok && json.reload) {
                // Ein neues Bild erscheint an mehreren Stellen der Seite: neu laden
                window.location.reload();

                return;
            }
            if (antwort.ok) {
                gespeichert = gesendet;
                dateienGeaendert.clear();
                felder().forEach((f) => {
                    if (f instanceof HTMLInputElement && f.type === 'file') {
                        f.value = '';
                    }
                });
                for (const [name, wert] of freigegeben) {
                    if (gleich(gesendet.get(name), wert)) {
                        freigegeben.delete(name);
                    }
                }
                // Abgelehnte Felder, die noch den abgelehnten Wert zeigen, behalten ihren Fehler
                const offen = Array.from(abgelehnt.entries()).filter(
                    ([name, wert]) => gleich(werte(form).get(name), wert),
                );
                if (offen.length === 0) {
                    abgelehnt.clear();
                    fehlerLeeren();
                }
                const zeit = new Intl.DateTimeFormat(
                    document.documentElement.lang || undefined,
                    { hour: '2-digit', minute: '2-digit' },
                ).format(new Date());
                zeige(`${json.message ?? texte.gespeichert} · ${zeit}`, 'ok');
                if (json.values && form.dataset.autosaveGruppe) {
                    document.dispatchEvent(
                        new CustomEvent('basalto:autosave-gespeichert', {
                            detail: {
                                gruppe: form.dataset.autosaveGruppe,
                                form,
                                werte: json.values,
                            },
                        }),
                    );
                }
            } else if (antwort.status === 422 && json.errors) {
                zeige(
                    `${texte.fehler} — ${fehlerZeigen(json.errors)}`,
                    'fehler',
                );
            } else {
                // Sitzung abgelaufen (419), abgemeldet (401) oder Serverfehler: der Knopf kommt zurück
                knoepfe.forEach((k) => (k.hidden = false));
                zeige(
                    json.message
                        ? `${texte.fehler} — ${json.message}`
                        : texte.neuLaden,
                    'fehler',
                );
            }
        } catch {
            knoepfe.forEach((k) => (k.hidden = false));
            zeige(texte.neuLaden, 'fehler');
        } finally {
            laeuft = false;
            if (nochmal) {
                nochmal = false;
                void speichern();
            }
        }
    }

    function spaeter(): void {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(() => void speichern(), pause);
    }

    function freigeben(feld: Feld): void {
        freigegeben.set(feld.name, werte(form).get(feld.name) ?? []);
    }

    form.addEventListener('input', (event) => {
        const feld = event.target as Feld;
        if (!feld?.name) {
            return;
        }
        const art = speicherart(feld);
        if (art === 'pause') {
            spaeter();
        } else if (art === 'verlassen') {
            const { wartend } = nutzlast();
            zeige(wartend.length > 0 ? texte.wartet : '', 'still');
        }
    });
    form.addEventListener('change', (event) => {
        const feld = event.target as Feld;
        if (!feld?.name) {
            return;
        }
        if (feld instanceof HTMLInputElement && feld.type === 'file') {
            dateienGeaendert.add(feld.name);
        }
        const art = speicherart(feld);
        if (art === 'sofort') {
            void speichern();
        } else if (art === 'verlassen') {
            freigeben(feld);
            void speichern();
        } else {
            // Text: «change» kommt beim Verlassen — was noch wartet, geht jetzt
            void speichern();
        }
    });
    form.addEventListener('keydown', (event) => {
        const feld = event.target as Feld;
        if (!feld?.name || speicherart(feld) !== 'verlassen') {
            return;
        }
        if (event.key === 'Enter' && !(feld instanceof HTMLTextAreaElement)) {
            event.preventDefault();
            freigeben(feld);
            void speichern();
        } else if (event.key === 'Escape') {
            const alt = gespeichert.get(feld.name) ?? [''];
            feld.value = alt[0] ?? '';
            zeige('', 'still');
        }
    });
    // Enter in einem Textfeld sendet sonst das Formular klassisch ab
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        felder().forEach((f) =>
            freigegeben.set(f.name, werte(form).get(f.name) ?? []),
        );
        void speichern();
    });
    // Gleiche Daten in einem anderen Formular derselben Gruppe (Kundendaten) nachziehen
    document.addEventListener('basalto:autosave-gespeichert', (event) => {
        const detail = (
            event as CustomEvent<{
                gruppe: string;
                form: HTMLFormElement;
                werte: Record<string, string>;
            }>
        ).detail;
        if (
            detail.form === form ||
            detail.gruppe !== form.dataset.autosaveGruppe
        ) {
            return;
        }
        for (const [name, wert] of Object.entries(detail.werte)) {
            const feld = felder().find((f) => f.name === name);
            if (
                feld &&
                document.activeElement !== feld &&
                !(
                    feld instanceof HTMLInputElement &&
                    ['checkbox', 'radio', 'file'].includes(feld.type)
                )
            ) {
                feld.value = wert ?? '';
                gespeichert.set(name, [wert ?? '']);
            }
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (timer || laeuft) {
            event.preventDefault();
        }
    });
}

/** Kurzer Fingerabdruck eines Texts (djb2) — für die Ausgangsfassung eines Entwurfs. */
function fingerabdruck(text: string): string {
    let hash = 5381;
    for (let i = 0; i < text.length; i++) {
        hash = ((hash << 5) + hash + text.charCodeAt(i)) | 0;
    }

    return (hash >>> 0).toString(36);
}

/**
 * Entwurf im Browser für Text mit Knopf (CR-013): `data-entwurf="schlüssel"` an einem Textfeld hält das Getippte, bis
 * das Formular gesendet ist — ein Neuladen verliert nichts. Ein Entwurf gilt nur für dieselbe Ausgangsfassung (eine
 * inzwischen geänderte Bewertung überschreibt er nicht) und höchstens 30 Tage.
 */
export function enhanceDraft(
    feld: HTMLInputElement | HTMLTextAreaElement,
): void {
    const schluessel = `basalto-entwurf:${feld.dataset.entwurf ?? ''}`;
    const basis = fingerabdruck(feld.defaultValue);
    try {
        const gespeichert = JSON.parse(
            window.localStorage.getItem(schluessel) ?? 'null',
        ) as {
            at?: number;
            text?: string;
            base?: string;
        } | null;
        if (
            gespeichert?.text &&
            gespeichert.base === basis &&
            typeof gespeichert.at === 'number' &&
            Date.now() - gespeichert.at < 30 * 86400000 &&
            feld.value === feld.defaultValue
        ) {
            feld.value = gespeichert.text;
        }
    } catch {
        // Speicher gesperrt oder kein JSON: ohne Entwurf weiter
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    feld.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            try {
                if (feld.value === feld.defaultValue) {
                    window.localStorage.removeItem(schluessel);
                } else {
                    window.localStorage.setItem(
                        schluessel,
                        JSON.stringify({
                            at: Date.now(),
                            text: feld.value,
                            base: basis,
                        }),
                    );
                }
            } catch {
                // Speicher voll oder gesperrt
            }
        }, 400);
    });
    feld.form?.addEventListener('submit', () => {
        clearTimeout(timer);
        try {
            window.localStorage.removeItem(schluessel);
        } catch {
            // nichts zu tun
        }
    });
}
