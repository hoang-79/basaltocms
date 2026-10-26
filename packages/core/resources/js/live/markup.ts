import type { LiveChange, LiveClient } from './client';
import { showDesktop } from './desktop';

/**
 * CR-008 (LV-12): Stellen im Markup, die sich selbst aktualisieren — ohne eigenes Skript eines Moduls. Der Server setzt
 * `data-live`, `data-live-token`, `data-live-site`, `data-live-version` und `data-live-aktion` (Live::attributes):
 *
 * - `hinweis` — das (verborgene) Element erscheint, sobald sich etwas ändert, etwa «Neue Beiträge — aktualisieren»;
 * - `zaehler` — zeigt die Zahl `data-live-feld` aus den Nutzdaten, verborgen bei 0;
 * - `nachladen` — holt `data-live-quelle?nach=<data-live-nach>` (JSON `{anzahl, html, url, nach}`), zeigt die Anzahl
 *   im Element `[data-live-text]` (Texte `data-live-eins` und `data-live-mehr` mit `:count`) und fügt das HTML auf
 *   Klick in `data-live-ziel` ein; ohne HTML führt der Klick zu `url`.
 * - `schreibt` (TB 49) — «Wer schreibt gerade»: Namen aus den Nutzdaten `schreibt` ohne die eigene Person
 *   (`data-live-ich`), Texte `data-live-eins` (`:name`), `data-live-zwei` (`:a`, `:b`) und `data-live-mehr`
 *   (`:count`); jede Meldung verfällt nach ihren `rest` Sekunden, auch ohne neue Abfrage.
 */
interface Fragment {
    anzahl: number;
    html: string | null;
    url: string | null;
    nach: number;
}

export function enhanceLive(root: ParentNode, client: LiveClient): void {
    root.querySelectorAll<HTMLElement>('[data-live]').forEach((element) => {
        if (element.dataset.liveBereit === '1') {
            return;
        }
        element.dataset.liveBereit = '1';
        const { live, liveToken, liveSite, liveVersion, liveAktion, liveTakt } =
            element.dataset;
        if (!live || !liveToken) {
            return;
        }
        const handler = handlerFor(element, liveAktion ?? 'hinweis');
        client.watch(
            Number(liveSite ?? 0),
            live,
            liveToken,
            Number(liveVersion ?? 0),
            handler,
            Number(liveTakt ?? 0),
        );
    });
}

function handlerFor(
    element: HTMLElement,
    action: string,
): (change: LiveChange) => void {
    if (action === 'zaehler') {
        return (change) => {
            const field = element.dataset.liveFeld ?? '';
            const value = Number(change.data?.[field] ?? Number.NaN);
            if (Number.isNaN(value)) {
                return;
            }
            const before = Number(element.textContent || '0');
            element.textContent = value > 0 ? String(value) : '';
            element.hidden = value <= 0;
            // Neueste Benachrichtigung als Desktop-Benachrichtigung, wenn eingeschaltet (nur am Zähler der Glocke)
            const newest = change.data?.neueste as
                | { id: string; titel: string; url: string }
                | null
                | undefined;
            if (field === 'benachrichtigungen' && value > before && newest) {
                showDesktop(newest.titel, null, newest.url, newest.id);
            }
        };
    }
    if (action === 'nachladen') {
        return loader(element);
    }
    if (action === 'schreibt') {
        return typing(element);
    }

    return () => {
        element.hidden = false;
    };
}

function loader(element: HTMLElement): (change: LiveChange) => void {
    let pending: Fragment | null = null;
    const button =
        element.querySelector<HTMLButtonElement>('[data-live-zeigen]') ??
        element.querySelector<HTMLButtonElement>('button');
    button?.addEventListener('click', () => {
        if (!pending) {
            return;
        }
        if (pending.html === null) {
            if (pending.url) {
                window.location.assign(pending.url);
            }

            return;
        }
        const target = document.querySelector(element.dataset.liveZiel ?? '');
        if (target) {
            const marker = document.createElement('template');
            marker.innerHTML = pending.html;
            const added = [...marker.content.children];
            target.append(marker.content);
            element.dataset.liveNach = String(pending.nach);
            document.dispatchEvent(
                new CustomEvent('basalto:inhalt', { detail: { root: target } }),
            );
            added[0]?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
        pending = null;
        element.hidden = true;
    });

    return () => {
        const source = element.dataset.liveQuelle;
        if (!source) {
            return;
        }
        const url = new URL(source, window.location.href);
        url.searchParams.set('nach', element.dataset.liveNach ?? '0');
        void fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? (response.json() as Promise<Fragment>) : null,
            )
            .then((fragment) => {
                if (!fragment || fragment.anzahl <= 0) {
                    return;
                }
                pending = fragment;
                const text = element.querySelector('[data-live-text]');
                const template =
                    fragment.anzahl === 1
                        ? (element.dataset.liveEins ?? '')
                        : (element.dataset.liveMehr ?? '');
                if (text && template) {
                    text.textContent = template.replace(
                        ':count',
                        String(fragment.anzahl),
                    );
                }
                element.hidden = false;
            })
            .catch(() => undefined);
    };
}

/** Der Satz für «Wer schreibt gerade» — eine, zwei oder mehr Personen. */
export function typingText(
    names: string[],
    texts: { eins: string; zwei: string; mehr: string },
): string {
    if (names.length === 0) {
        return '';
    }
    if (names.length === 1) {
        return texts.eins.replace(':name', names[0]);
    }
    if (names.length === 2) {
        return texts.zwei.replace(':a', names[0]).replace(':b', names[1]);
    }

    return texts.mehr.replace(':count', String(names.length));
}

function typing(element: HTMLElement): (change: LiveChange) => void {
    const self = Number(element.dataset.liveIch ?? 0);
    const present = new Map<number, { name: string; until: number }>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    const render = (): void => {
        const now = Date.now();
        for (const [id, entry] of present) {
            if (entry.until <= now) {
                present.delete(id);
            }
        }
        const names = [...present.values()].map((entry) => entry.name);
        const target = element.querySelector('[data-live-text]') ?? element;
        target.textContent = typingText(names, {
            eins: element.dataset.liveEins ?? ':name',
            zwei: element.dataset.liveZwei ?? ':a, :b',
            mehr: element.dataset.liveMehr ?? ':count',
        });
        element.hidden = names.length === 0;
        clearTimeout(timer);
        if (names.length > 0) {
            const next = Math.min(
                ...[...present.values()].map((entry) => entry.until),
            );
            timer = setTimeout(render, Math.max(250, next - now + 50));
        }
    };

    return (change) => {
        const list = Array.isArray(change.data?.schreibt)
            ? (change.data.schreibt as unknown[])
            : [];
        present.clear();
        for (const entry of list) {
            const { id, name, rest } = (entry ?? {}) as {
                id?: unknown;
                name?: unknown;
                rest?: unknown;
            };
            if (
                typeof id === 'number' &&
                id !== self &&
                typeof name === 'string' &&
                name !== ''
            ) {
                present.set(id, {
                    name,
                    until:
                        Date.now() +
                        Math.min(30, Math.max(1, Number(rest) || 8)) * 1000,
                });
            }
        }
        render();
    };
}
