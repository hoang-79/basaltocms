/**
 * Codeblöcke der Community (TB 49): die Sprachen der Auswahl im Editor (dieselbe Liste wie `CommunityRichText::LANGUAGES`
 * auf dem Server) und die nachträgliche Hervorhebung beim Lesen. Die Grammatiken kommen als eigener Teil
 * (`highlighter.ts`) und nur auf Seiten mit Code; dazu je Block ein Knopf «Kopieren».
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

/** Alle Codeblöcke unter `root` hervorheben und mit «Kopieren» versehen — jeden nur einmal. */
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
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'forum-code__kopieren';
        button.textContent = copy;
        button.addEventListener('click', () => {
            void navigator.clipboard
                .writeText(code.textContent ?? '')
                .then(() => {
                    button.textContent = copied;
                    window.setTimeout(() => (button.textContent = copy), 1800);
                });
        });
        caption.append(button);
    });
    const { highlightInto } = await import('./highlighter');
    blocks.forEach((code) => highlightInto(code));
}
