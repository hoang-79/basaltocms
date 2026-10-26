/**
 * Codeblöcke der Community (TB 49): die Sprachen der Auswahl im Editor (dieselbe Liste wie `CommunityRichText::LANGUAGES`
 * auf dem Server) und die nachträgliche Hervorhebung beim Lesen. Die Grammatiken kommen als eigener Teil
 * (`highlighter.ts`) und nur auf Seiten mit Code; dazu je Block ein Knopf «Kopieren». Seit TB 71 steht jede Zeile in
 * einem eigenen Element mit ihrer Nummer, und «Mehr» bricht abgeschnittene Zeilen um, bis der ganze Code zu sehen ist.
 */
export const CODE_LANGUAGES: { value: string; label: string }[] = [
    { value: 'apache', label: 'Apache' },
    { value: 'bash', label: 'Bash' },
    { value: 'c', label: 'C' },
    { value: 'cpp', label: 'C++' },
    { value: 'csharp', label: 'C#' },
    { value: 'css', label: 'CSS' },
    { value: 'diff', label: 'Diff' },
    { value: 'dockerfile', label: 'Docker' },
    { value: 'go', label: 'Go' },
    { value: 'xml', label: 'HTML/XML' },
    { value: 'http', label: 'HTTP' },
    { value: 'ini', label: 'INI/TOML' },
    { value: 'java', label: 'Java' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'json', label: 'JSON' },
    { value: 'kotlin', label: 'Kotlin' },
    { value: 'lava', label: 'Lava' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'nginx', label: 'Nginx' },
    { value: 'php', label: 'PHP' },
    { value: 'powershell', label: 'PowerShell' },
    { value: 'python', label: 'Python' },
    { value: 'ruby', label: 'Ruby' },
    { value: 'rust', label: 'Rust' },
    { value: 'scss', label: 'SCSS' },
    { value: 'shell', label: 'Shell' },
    { value: 'sql', label: 'SQL' },
    { value: 'swift', label: 'Swift' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'yaml', label: 'YAML' },
];

export function languageLabel(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    if (value === 'plaintext') {
        return 'Text';
    }

    return (
        CODE_LANGUAGES.find((l) => l.value === value)?.label ??
        value.toUpperCase()
    );
}

const COPY: Record<string, [string, string]> = {
    de: ['Kopieren', 'Kopiert'],
    en: ['Copy', 'Copied'],
    fr: ['Copier', 'Copié'],
    it: ['Copia', 'Copiato'],
};

const MORE: Record<string, [string, string]> = {
    de: ['Mehr', 'Weniger'],
    en: ['More', 'Less'],
    fr: ['Plus', 'Moins'],
    it: ['Altro', 'Meno'],
};

let blockCount = 0;

/**
 * Jede Zeile eines (hervorgehobenen) Codes in ein eigenes `span.forum-code__zeile` legen. Spans der Hervorhebung, die
 * über ein Zeilenende reichen (mehrzeilige Kommentare, Zeichenketten), werden geschlossen und in der nächsten Zeile
 * neu geöffnet. Ein Zeilenumbruch am Ende ergibt keine leere Zeile. Liefert die Zahl der Zeilen.
 */
export function splitLines(code: HTMLElement): number {
    const lines: HTMLElement[] = [];
    const open: Element[] = [];
    let chain: Element[] = [];
    const newLine = (): void => {
        const line = document.createElement('span');
        line.className = 'forum-code__zeile';
        lines.push(line);
        chain = [line];
        open.forEach((element) => {
            const copy = element.cloneNode(false) as Element;
            chain[chain.length - 1].append(copy);
            chain.push(copy);
        });
    };
    const walk = (node: Node): void => {
        Array.from(node.childNodes).forEach((child) => {
            if (child.nodeType === Node.TEXT_NODE) {
                (child.textContent ?? '').split('\n').forEach((part, i) => {
                    if (i > 0) {
                        newLine();
                    }
                    if (part !== '') {
                        chain[chain.length - 1].append(part);
                    }
                });
            } else if (child instanceof Element) {
                const copy = child.cloneNode(false) as Element;
                chain[chain.length - 1].append(copy);
                chain.push(copy);
                open.push(child);
                walk(child);
                open.pop();
                chain.pop();
            }
        });
    };
    newLine();
    walk(code);
    if (lines.length > 1 && lines[lines.length - 1].textContent === '') {
        lines.pop();
    }
    code.replaceChildren(...lines);

    return lines.length;
}

/**
 * Zeilen legen, Nummern an die Zeilen hängen (statt der Spalte vom Server) und «Mehr» anbieten, solange Zeilen
 * abgeschnitten sind. «Mehr» bricht die Zeilen um, «Weniger» stellt das Rollen wieder her.
 */
export function enhanceLines(
    code: HTMLElement,
    lang: string,
    observe: (target: Element, check: () => void) => void = observeSize,
): void {
    const figure = code.closest('figure');
    const frame = code.closest<HTMLElement>('.forum-code__rahmen');
    if (!figure || !frame || figure.dataset.zeilen) {
        return;
    }
    const column = figure.querySelector('.forum-code__zeilen');
    const count = splitLines(code);
    figure.dataset.zeilen = String(count);
    if (column) {
        const start = parseInt(column.textContent ?? '1', 10) || 1;
        figure.classList.add('forum-code--nummern');
        code.style.counterReset = `zeile ${start - 1}`;
        figure.style.setProperty(
            '--ziffern',
            String(String(start + count - 1).length),
        );
    }
    const caption = figure.querySelector('figcaption');
    if (!caption) {
        return;
    }
    const [more, less] = MORE[lang] ?? MORE.de;
    frame.id ||= `forum-code-${++blockCount}`;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'forum-code__mehr';
    button.textContent = more;
    button.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', frame.id);
    button.addEventListener('click', () => {
        const whole = !figure.classList.contains('forum-code--ganz');
        figure.classList.toggle('forum-code--ganz', whole);
        button.textContent = whole ? less : more;
        button.setAttribute('aria-expanded', String(whole));
    });
    const copy = caption.querySelector('.forum-code__kopieren');
    if (copy) {
        copy.before(button);
    } else {
        caption.append(button);
    }
    observe(frame, () => {
        if (!figure.classList.contains('forum-code--ganz')) {
            button.hidden = frame.scrollWidth <= frame.clientWidth + 1;
        }
    });
}

function observeSize(target: Element, check: () => void): void {
    check();
    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(check).observe(target);
    }
}

/** Alle Codeblöcke unter `root` hervorheben und mit «Kopieren» und «Mehr» versehen — jeden nur einmal. */
export async function enhanceCode(root: ParentNode): Promise<void> {
    const blocks = Array.from(
        root.querySelectorAll<HTMLElement>(
            'figure.forum-code code:not([data-hervorgehoben])',
        ),
    );
    if (blocks.length === 0) {
        return;
    }
    const lang = (document.documentElement.lang || 'de').slice(0, 2);
    const [copy, copied] = COPY[lang] ?? COPY.de;
    blocks.forEach((code) => {
        code.dataset.hervorgehoben = '1';
        const caption = code.closest('figure')?.querySelector('figcaption');
        if (
            !caption ||
            !navigator.clipboard ||
            caption.querySelector('.forum-code__kopieren')
        ) {
            return;
        }
        // der Text vor dem Legen der Zeilen — mit seinen Zeilenumbrüchen
        const source = code.textContent ?? '';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'forum-code__kopieren';
        button.textContent = copy;
        button.addEventListener('click', () => {
            void navigator.clipboard.writeText(source).then(() => {
                button.textContent = copied;
                window.setTimeout(() => (button.textContent = copy), 1800);
            });
        });
        caption.append(button);
    });
    try {
        const { highlightInto } = await import('./highlighter');
        blocks.forEach((code) => highlightInto(code));
    } finally {
        blocks.forEach((code) => enhanceLines(code, lang));
    }
}
