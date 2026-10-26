import {
    InvalidDomainError,
    NotSupportedError,
    PasskeyExistsError,
    Passkeys,
    UserCancelledError,
} from '@laravel/passkeys';

/**
 * TB 53: Passkeys im Kundenkonto — «Mit Passkey anmelden» (`button[data-konto-passkey]`) und «Passkey hinzufügen»
 * (`form[data-konto-passkey-neu]`). Kann der Browser keine Passkeys, bleibt alles mit `data-konto-passkey-zeigen`
 * verborgen; die Seite funktioniert dann mit dem Code per Mail. Texte kommen aus dem Markup (Sprache der Seite).
 */

/** Meldung am Knopf — Fehler des Browsers in der Sprache der Seite, Meldungen des Servers wie sie kommen. */
export function passkeyFehler(fehler: unknown, texte: DOMStringMap): string {
    if (fehler instanceof PasskeyExistsError) {
        return texte.vorhanden ?? '';
    }
    if (
        fehler instanceof InvalidDomainError ||
        fehler instanceof NotSupportedError
    ) {
        return texte.geht ?? '';
    }

    return fehler instanceof Error && fehler.message !== ''
        ? fehler.message
        : (texte.geht ?? '');
}

function melde(ziel: Element, text: string): void {
    const feld = ziel.parentElement?.querySelector<HTMLElement>(
        '[data-konto-passkey-fehler]',
    );
    if (feld) {
        feld.textContent = text;
        feld.hidden = text === '';
    }
}

/**
 * Zur Adresse aus der Antwort — liegt sie auf derselben Seite (nur ein anderer Anker), lädt die Seite neu: Sonst
 * sprünge der Browser nur zum Anker und zeigte weiter den alten Stand.
 */
export function weiter(
    ziel: string | undefined,
    ort: Pick<
        Location,
        'href' | 'hash' | 'assign' | 'reload'
    > = window.location,
): void {
    const neu = new URL(ziel ?? ort.href, ort.href);
    const jetzt = new URL(ort.href);
    if (
        neu.origin === jetzt.origin &&
        neu.pathname === jetzt.pathname &&
        neu.search === jetzt.search
    ) {
        ort.hash = neu.hash;
        ort.reload();

        return;
    }
    ort.assign(neu.href);
}

async function laufen(
    knopf: HTMLButtonElement,
    arbeit: () => Promise<unknown>,
): Promise<void> {
    const beschriftung = knopf.querySelector('span') ?? knopf;
    const vorher = beschriftung.textContent;
    knopf.disabled = true;
    beschriftung.textContent = knopf.dataset.laeuft ?? vorher;
    melde(knopf, '');
    try {
        const antwort = (await arbeit()) as { redirect?: string } | undefined;
        weiter(antwort?.redirect);
    } catch (fehler) {
        knopf.disabled = false;
        beschriftung.textContent = vorher;
        if (!(fehler instanceof UserCancelledError)) {
            melde(knopf, passkeyFehler(fehler, knopf.dataset));
        }
    }
}

export function passkeysStarten(wurzel: ParentNode = document): void {
    if (!Passkeys.isSupported()) {
        return;
    }
    wurzel
        .querySelectorAll<HTMLElement>('[data-konto-passkey-zeigen]')
        .forEach((element) => {
            element.hidden = false;
        });
    wurzel
        .querySelectorAll<HTMLButtonElement>('button[data-konto-passkey]')
        .forEach((knopf) => {
            if (knopf.dataset.kontoAktiv) {
                return;
            }
            knopf.dataset.kontoAktiv = '1';
            knopf.addEventListener('click', () => {
                void laufen(knopf, () =>
                    Passkeys.verify({
                        routes: {
                            options: knopf.dataset.optionen ?? '',
                            submit: knopf.dataset.anmelden ?? '',
                        },
                    }),
                );
            });
        });
    wurzel
        .querySelectorAll<HTMLFormElement>('form[data-konto-passkey-neu]')
        .forEach((formular) => {
            if (formular.dataset.kontoAktiv) {
                return;
            }
            formular.dataset.kontoAktiv = '1';
            formular.addEventListener('submit', (ereignis) => {
                ereignis.preventDefault();
                const knopf = formular.querySelector<HTMLButtonElement>(
                    'button[type=submit]',
                );
                const name =
                    formular
                        .querySelector<HTMLInputElement>('input[name=name]')
                        ?.value.trim() ?? '';
                if (!knopf || name === '') {
                    return;
                }
                void laufen(knopf, () =>
                    Passkeys.register({
                        name,
                        routes: {
                            options: formular.dataset.optionen ?? '',
                            submit: formular.dataset.speichern ?? '',
                        },
                    }),
                );
            });
        });
}

passkeysStarten();
