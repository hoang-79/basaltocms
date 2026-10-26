/**
 * CR-006 (§ 3.2): «Ähnliche Themen» beim Eröffnen. Am Titelfeld mit `data-insel="aehnlich"` fragt die Insel nach
 * einer kurzen Pause den Server und zeigt bis zu fünf Themen mit ähnlichem Titel — ohne Skript fehlt nur der Hinweis.
 */
export type SimilarThread = {
    title: string;
    url: string;
    replies: number;
    done: boolean;
};

export function renderSimilar(
    intro: string,
    items: SimilarThread[],
): HTMLElement[] {
    if (items.length === 0) {
        return [];
    }
    const head = document.createElement('p');
    head.textContent = intro;
    const list = document.createElement('ul');
    for (const item of items) {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = item.url;
        a.textContent = item.title;
        a.target = '_blank';
        a.rel = 'noopener';
        li.append(a);
        if (item.done) {
            li.append(' ✓');
        }
        list.append(li);
    }

    return [head, list];
}

export function enhanceSimilar(
    input: HTMLInputElement,
    fetcher: typeof fetch = fetch,
): void {
    const box = document.getElementById('forum-aehnlich');
    const url = input.dataset.inselUrl;
    if (!box || !url) {
        return;
    }
    let timer: number | undefined;
    let last = '';
    const run = async (): Promise<void> => {
        const title = input.value.trim();
        if (title.length < 4 || title === last) {
            return;
        }
        last = title;
        const query = new URLSearchParams({
            titel: title,
            basis: input.dataset.inselBasis ?? '',
        });
        const response = await fetcher(`${url}?${query.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const items = response.ok
            ? ((await response.json()) as SimilarThread[])
            : [];
        box.replaceChildren(
            ...renderSimilar(input.dataset.inselText ?? '', items),
        );
        box.hidden = items.length === 0;
    };
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => void run(), 350);
    });
}
