/**
 * TB 71: Live-Suche im Forum. Zwei Inseln, beide ohne Skript entbehrlich:
 *
 * - `input[data-suche="vorschlag"]` (Suchfeld der Forumsleiste): Beim Tippen fragt die Insel nach einer kurzen Pause
 *   den Server und zeigt bis zu fünf Themen als Liste unter dem Feld, dazu «Alle n Treffer anzeigen». Bedienbar mit
 *   Pfeiltasten, Enter und Escape; Combobox nach ARIA (`aria-activedescendant`).
 * - `form[data-suche="seite"]` (Suchseite): Die Treffer erneuern sich beim Tippen — die Insel holt dieselbe Seite mit
 *   `?q=` und tauscht nur `#forum-suche-ergebnis` aus, die Adresse läuft mit.
 */
export type LiveHit = {
    title: string;
    url: string;
    excerpt: string;
    meta?: string;
};
export type LiveResult = { total: number; items: LiveHit[] };

/** Der Auszug als Knoten: Text bleibt Text, nur `<mark>` übernimmt die Insel — nie anderes Markup. */
export function safeExcerpt(html: string): DocumentFragment {
    const out = document.createDocumentFragment();
    const template = document.createElement('template');
    template.innerHTML = html;
    template.content.childNodes.forEach((node) => {
        if (node.nodeName === 'MARK') {
            const mark = document.createElement('mark');
            mark.textContent = node.textContent;
            out.append(mark);
        } else {
            out.append(document.createTextNode(node.textContent ?? ''));
        }
    });

    return out;
}

export function enhanceSuggest(
    input: HTMLInputElement,
    fetcher: typeof fetch = fetch,
    wait = 250,
): void {
    const url = input.dataset.sucheUrl;
    const form = input.form;
    if (!url || !form) {
        return;
    }
    const list = document.createElement('div');
    list.id = `${input.id}-vorschlaege`;
    list.className = 'forum-vorschlag';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', input.dataset.sucheTitel ?? '');
    list.hidden = true;
    form.append(list);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);
    input.autocomplete = 'off';

    let options: HTMLAnchorElement[] = [];
    let active = -1;
    let last = '';
    let timer: number | undefined;
    let controller: AbortController | undefined;

    const select = (index: number): void => {
        active = index;
        options.forEach((option, i) =>
            option.setAttribute('aria-selected', String(i === index)),
        );
        if (index >= 0) {
            input.setAttribute('aria-activedescendant', options[index].id);
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };
    const close = (): void => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        select(-1);
    };
    // Die Liste bleibt im Bild: höchstens 26rem bzw. Bildschirm minus Rand, links bündig mit dem Feld, wo es passt
    const place = (): void => {
        const margin = 8;
        const width = Math.min(416, window.innerWidth - 2 * margin);
        const field = input.getBoundingClientRect();
        const box = form.getBoundingClientRect();
        const left = Math.max(
            margin,
            Math.min(field.left, window.innerWidth - margin - width),
        );
        list.style.width = `${width}px`;
        list.style.left = `${left - box.left}px`;
        list.style.right = 'auto';
    };
    const open = (): void => {
        if (options.length > 0) {
            place();
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
    };
    const option = (
        href: string,
        id: string,
        cls: string,
    ): HTMLAnchorElement => {
        const a = document.createElement('a');
        a.href = href;
        a.id = id;
        a.className = cls;
        a.tabIndex = -1;
        a.setAttribute('role', 'option');
        a.setAttribute('aria-selected', 'false');
        return a;
    };
    const render = (query: string, result: LiveResult): void => {
        options = result.items.map((hit, i) => {
            const a = option(
                hit.url,
                `${list.id}-${i}`,
                'forum-vorschlag__treffer',
            );
            const title = document.createElement('b');
            title.textContent = hit.title;
            const excerpt = document.createElement('span');
            excerpt.append(safeExcerpt(hit.excerpt));
            a.append(title);
            // TB 71: wer den passenden Beitrag wann geschrieben hat
            if (hit.meta) {
                const meta = document.createElement('small');
                meta.textContent = hit.meta;
                a.append(meta);
            }
            a.append(excerpt);
            return a;
        });
        const all = option(
            `${form.action}?${new URLSearchParams({ q: query }).toString()}`,
            `${list.id}-alle`,
            'forum-vorschlag__alle',
        );
        all.textContent =
            result.total === 0
                ? (input.dataset.sucheLeer ?? '')
                : (input.dataset.sucheAlle ?? '').replace(
                      ':count',
                      String(result.total),
                  );
        options.push(all);
        list.replaceChildren(...options);
        select(-1);
        open();
    };
    const run = async (): Promise<void> => {
        const query = input.value.trim();
        if (query === last) {
            return;
        }
        last = query;
        controller?.abort();
        if (query.length < 2) {
            options = [];
            list.replaceChildren();
            close();
            return;
        }
        controller = new AbortController();
        const params = new URLSearchParams({
            q: query,
            sprache: document.documentElement.lang || '',
        });
        try {
            const response = await fetcher(`${url}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (response.ok && input.value.trim() === query) {
                render(query, (await response.json()) as LiveResult);
            }
        } catch {
            // abgebrochen oder ohne Netz: das Feld bleibt ein gewöhnliches Suchfeld
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => void run(), wait);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
            return;
        }
        if (list.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            select((active + 1) % options.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            select(active <= 0 ? options.length - 1 : active - 1);
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            window.location.assign(options[active].href);
        }
    });
    input.addEventListener('focus', open);
    window.addEventListener('resize', () => {
        if (!list.hidden) {
            place();
        }
    });
    // Ein Klick in die Liste nimmt dem Feld den Fokus nicht, bevor der Link greift
    list.addEventListener('mousedown', (event) => event.preventDefault());
    input.addEventListener('blur', () => window.setTimeout(close, 100));
}

export function enhanceSearchPage(
    form: HTMLFormElement,
    fetcher: typeof fetch = fetch,
    wait = 300,
): void {
    const input = form.querySelector<HTMLInputElement>('input[name="q"]');
    const target = document.getElementById('forum-suche-ergebnis');
    if (!input || !target) {
        return;
    }
    let last = input.value.trim();
    let timer: number | undefined;
    let controller: AbortController | undefined;
    const run = async (): Promise<void> => {
        const query = input.value.trim();
        // Ein einzelnes Zeichen sucht der Server nicht — die bisherigen Treffer bleiben stehen
        if (query === last || query.length === 1) {
            return;
        }
        last = query;
        controller?.abort();
        controller = new AbortController();
        const address = new URL(form.action, window.location.href);
        if (query !== '') {
            address.searchParams.set('q', query);
        }
        try {
            const response = await fetcher(address.toString(), {
                headers: { Accept: 'text/html' },
                signal: controller.signal,
            });
            if (!response.ok || input.value.trim() !== query) {
                return;
            }
            const page = new DOMParser().parseFromString(
                await response.text(),
                'text/html',
            );
            const fresh = page.getElementById('forum-suche-ergebnis');
            if (!fresh) {
                return;
            }
            target.replaceChildren(
                ...Array.from(fresh.childNodes, (node) =>
                    document.importNode(node, true),
                ),
            );
            window.history.replaceState(
                null,
                '',
                address.pathname + address.search,
            );
        } catch {
            // abgebrochen oder ohne Netz: «Suchen» geht weiter wie ohne Skript
        }
    };
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => void run(), wait);
    });
}
