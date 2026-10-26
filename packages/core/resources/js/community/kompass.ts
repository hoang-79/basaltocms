/**
 * TB 47: der Kompass am Rand eines Themas und die Tastenkürzel — kommt mit dem leichten Live-Bündel, damit lesende
 * Gäste ohne Editor auskommen. Der Kompass zeigt, welcher Beitrag gerade gelesen wird, und nimmt live nachgeladene
 * Beiträge als neue Waben auf; J und K blättern von Beitrag zu Beitrag, R springt zum Antworten. Ohne Skript bleiben
 * die Waben einfache Sprungmarken.
 */
const POSTS = '#forum-beitraege > li.forum-eintrag';

/** Tippt die Person gerade (Feld, Editor)? Dann gehören die Tasten ihr. */
export function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

/** Der nächste Beitrag beim Blättern — am Anfang und Ende bleibt es stehen. */
export function nextIndex(
    current: number,
    count: number,
    step: 1 | -1,
): number {
    if (count === 0) {
        return -1;
    }
    if (current < 0) {
        return step > 0 ? 0 : count - 1;
    }

    return Math.min(count - 1, Math.max(0, current + step));
}

function posts(root: ParentNode): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>(POSTS)];
}

function motion(): ScrollBehavior {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        ? 'auto'
        : 'smooth';
}

/** Zum Antworten: hinscrollen und den Editor (oder das erste Bedienelement) fokussieren. */
export function focusReply(doc: Document): boolean {
    const reply = doc.getElementById('antworten');
    if (!reply) {
        return false;
    }
    reply.scrollIntoView({ block: 'center', behavior: motion() });
    reply
        .querySelector<HTMLElement>(
            '[contenteditable="true"], textarea, input:not([type="hidden"]), a, button',
        )
        ?.focus({ preventScroll: true });

    return true;
}

export function enhanceKompass(doc: Document = document): void {
    doc.querySelectorAll<HTMLAnchorElement>('[data-forum-antworten]').forEach(
        (link) =>
            link.addEventListener('click', (event) => {
                if (focusReply(doc)) {
                    event.preventDefault();
                }
            }),
    );
    const kompass = doc.querySelector<HTMLElement>('[data-kompass]');
    const list = kompass?.querySelector<HTMLOListElement>('.forum-waben');
    if (!kompass || !list || kompass.dataset.kompassAktiv) {
        return;
    }
    kompass.dataset.kompassAktiv = '1';
    let current = -1;
    const cellFor = (article: Element): HTMLAnchorElement | null =>
        list.querySelector<HTMLAnchorElement>(
            `a[href="#${CSS.escape(article.id)}"]`,
        );
    const mark = (index: number): void => {
        current = index;
        list.querySelectorAll('a[aria-current]').forEach((a) =>
            a.removeAttribute('aria-current'),
        );
        const article = posts(doc)[index]?.querySelector('article.forum-post');
        if (article) {
            cellFor(article)?.setAttribute('aria-current', 'true');
        }
    };
    const observer =
        'IntersectionObserver' in window
            ? new IntersectionObserver(
                  () => {
                      const top = posts(doc).findIndex((li) => {
                          const box = li.getBoundingClientRect();

                          return (
                              box.bottom > 120 &&
                              box.top < window.innerHeight * 0.6
                          );
                      });
                      if (top >= 0) {
                          mark(top);
                      }
                  },
                  { rootMargin: '-80px 0px -40% 0px' },
              )
            : null;
    posts(doc).forEach((li) => observer?.observe(li));
    // Live nachgeladene Beiträge: eine neue Wabe je Beitrag, kurz aufleuchtend
    doc.addEventListener('basalto:inhalt', () => {
        for (const li of posts(doc)) {
            const article = li.querySelector('article.forum-post');
            if (!article || cellFor(article)) {
                continue;
            }
            const number = li.dataset.nummer ?? '';
            const link = doc.createElement('a');
            link.href = `#${article.id}`;
            link.className = 'ist-neu ist-frisch';
            link.title = (kompass.dataset.kompassTitel ?? ':number').replace(
                ':number',
                number,
            );
            const label = doc.createElement('span');
            label.textContent = number;
            link.append(label);
            const item = doc.createElement('li');
            item.append(link);
            list.append(item);
            li.classList.add('forum-eintrag--frisch');
            observer?.observe(li);
        }
    });
    doc.addEventListener('keydown', (event) => {
        if (
            event.defaultPrevented ||
            event.altKey ||
            event.ctrlKey ||
            event.metaKey ||
            isTyping(event.target)
        ) {
            return;
        }
        const key = event.key.toLowerCase();
        if (key === 'j' || key === 'k') {
            const all = posts(doc);
            const index = nextIndex(current, all.length, key === 'j' ? 1 : -1);
            const article =
                all[index]?.querySelector<HTMLElement>('article.forum-post');
            if (!article) {
                return;
            }
            event.preventDefault();
            mark(index);
            article.tabIndex = -1;
            article.scrollIntoView({ block: 'start', behavior: motion() });
            article.focus({ preventScroll: true });
        } else if (key === 'r' && focusReply(doc)) {
            event.preventDefault();
        }
    });
}
