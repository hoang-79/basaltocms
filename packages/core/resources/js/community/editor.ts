import { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import StarterKit from '@tiptap/starter-kit';
import {
    AttachmentImage,
    Embed,
    ForumCodeBlock,
    ForumQuote,
    Highlight,
    Mention,
    Spoiler,
} from './nodes';
import { buildPanels } from './panels';
import { buildTools } from './tools';
import { buildToolbar } from './toolbar';
import { type TypingConfig, reportTyping } from './typing';

/**
 * CR-006 (FO-K5, TB 36/37): Der Besucher-Editor als Insel. Er legt sich über ein `<textarea data-insel="editor">` —
 * ohne Skript bleibt das Textfeld, der Server macht aus dem Text Absätze. Mit Skript schreibt der Editor bei jeder
 * Änderung das Dokument (TipTap-JSON) in ein verborgenes Feld `<name>_doc`; der Server säubert es
 * (`CommunityRichText`). Dazu, je nach `data-insel-*` am Textfeld:
 *
 * - **Anhänge** (`hochladen`): Knopf, Einfügen und Ziehen; Bilder landen im Text, andere Dateien in einer Liste,
 *   jede Kennung als `anhaenge_ids[]` im Formular;
 * - **Erwähnungen** (`namen`): `@` und ein paar Buchstaben schlagen Mitglieder vor;
 * - **Zitate** (`zitat`): die Knöpfe «Zitieren» und «Merken» an den Beiträgen der Seite;
 * - **Entwurf** (`entwurf`): im Browser gesichert, beim Laden wiederhergestellt, beim Absenden gelöscht;
 * - **Dokument** (`dokument`): Startinhalt beim Bearbeiten;
 * - TB 49: **Vorschau** (`vorschau`), **Wer schreibt gerade** (`schreibt`, JSON mit Adresse, Schlüssel und
 *   Unterschrift), **Regeln der Anhänge** (`regeln`, Text für den Reiter «Dateianhänge»).
 *
 * Die Werkzeugleiste folgt WoltLab (TB 49, `tools.ts`); unter dem Editor liegen Reiter für Smileys, Anhänge,
 * Einstellungen, Umfrage und die Vorschau (`panels.ts`).
 *
 * Beschriftungen kommen übersetzt aus `data-insel-texte`, damit die Insel keine Sprachdateien laden muss.
 */
type Texts = Record<string, string>;

type Uploaded = {
    id: number;
    url: string;
    name: string;
    size: number;
    image: boolean;
};

type Person = { id: number; name: string; gruppe?: boolean };

const DRAFT_DAYS = 7;

/** Aus einfachem Text die Absätze eines Dokuments — für ein schon ausgefülltes Feld (nach einem Fehler). */
export function docFromText(value: string): JSONContent {
    const paragraphs = value
        .split(/\n{2,}/)
        .map((part) => part.trim())
        .filter((part) => part !== '');

    return {
        type: 'doc',
        content: paragraphs.map((part) => ({
            type: 'paragraph',
            content: [{ type: 'text', text: part }],
        })),
    };
}

/** Ein `@name` direkt vor der Schreibmarke — oder null. */
export function mentionQuery(textBefore: string): string | null {
    const match = /(?:^|\s)@([\p{L}\p{N}._-]{1,30})$/u.exec(textBefore);

    return match ? match[1] : null;
}

function readJson<T>(value: string | undefined, fallback: T): T {
    try {
        const parsed: unknown = JSON.parse(value ?? '');

        return parsed !== null && typeof parsed === 'object'
            ? (parsed as T)
            : fallback;
    } catch {
        return fallback;
    }
}

function token(form: HTMLFormElement | null): string {
    return (
        form?.querySelector<HTMLInputElement>('input[name="_token"]')?.value ??
        ''
    );
}

function el<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    className: string,
    text = '',
): HTMLElementTagNameMap[K] {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== '') {
        node.textContent = text;
    }

    return node;
}

export function enhanceEditor(area: HTMLTextAreaElement): Editor | null {
    if (area.dataset.inselAktiv === '1' || !area.name) {
        return null;
    }
    area.dataset.inselAktiv = '1';
    const texts = readJson<Texts>(area.dataset.inselTexte, {});
    const say = (key: string, fallback: string): string =>
        texts[key] ?? fallback;
    const form = area.closest('form');
    const lang = document.documentElement.lang || 'de';

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = `${area.name}_doc`;
    const shell = el('div', 'insel-editor');
    shell.id = `${area.id || area.name}-insel`;
    const bar = el('div', 'insel-editor__leiste');
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', say('toolbar', 'Formatierung'));
    const surface = el('div', 'insel-editor__flaeche');
    const status = el('p', 'insel-editor__status');
    status.setAttribute('role', 'status');
    const files = el('ul', 'insel-editor__anhaenge');
    files.setAttribute('aria-label', say('attachments', 'Anhänge'));
    shell.append(bar, surface, status, files, hidden);
    area.after(shell);
    area.hidden = true;
    area.required = false;

    // Das Dateifeld ohne Skript weicht dem Knopf der Insel
    const fileField = form?.querySelector<HTMLInputElement>(
        'input[data-insel-dateien]',
    );
    const uploadUrl = area.dataset.inselHochladen;
    if (fileField && uploadUrl) {
        // `hidden` allein genügt nicht — Formularregeln eines Themes setzen `display`
        const wrapper =
            fileField.closest<HTMLElement>('.form-field') ?? fileField;
        wrapper.hidden = true;
        wrapper.style.display = 'none';
    }

    const draftKey = area.dataset.inselEntwurf
        ? `basalto-entwurf:${area.dataset.inselEntwurf}`
        : null;
    const startDoc = area.dataset.inselDokument
        ? readJson<JSONContent | null>(area.dataset.inselDokument, null)
        : null;
    const initial = area.value.trim();

    const editor = new Editor({
        element: surface,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3, 4] },
                horizontalRule: false,
                blockquote: false,
                codeBlock: false,
                link: {
                    openOnClick: false,
                    autolink: true,
                    protocols: ['http', 'https', 'mailto'],
                },
            }),
            ForumQuote,
            ForumCodeBlock,
            Highlight,
            Subscript,
            Superscript,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TableKit.configure({ table: { resizable: false } }),
            Spoiler,
            Embed,
            Mention,
            AttachmentImage.configure({ inline: false }),
        ],
        content: startDoc ?? (initial === '' ? '' : docFromText(initial)),
        editorProps: {
            attributes: {
                class: 'insel-editor__text',
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-label':
                    area.labels?.[0]?.textContent?.trim() ?? texts.label ?? '',
            },
            handlePaste: (_view, event) => {
                const list = event.clipboardData?.files;
                if (uploadUrl && list && list.length > 0) {
                    Array.from(list).forEach((file) => void upload(file));

                    return true;
                }

                return false;
            },
            handleDrop: (_view, event) => {
                const list = event.dataTransfer?.files;
                if (uploadUrl && list && list.length > 0) {
                    event.preventDefault();
                    Array.from(list).forEach((file) => void upload(file));

                    return true;
                }

                return false;
            },
            handleKeyDown: (_view, event) => mentionKey(event),
        },
        onUpdate: ({ editor: current }) => {
            sync(current);
            saveDraft(current);
            void suggest(current);
        },
        onSelectionUpdate: ({ editor: current }) => void suggest(current),
    });

    function sync(current: Editor): void {
        hidden.value = JSON.stringify(current.getJSON());
        area.value = current.getText({ blockSeparator: '\n\n' });
    }
    sync(editor);

    function announce(message: string): void {
        status.textContent = message;
    }

    // ── Anhänge ─────────────────────────────────────────────
    const picker = document.createElement('input');
    picker.type = 'file';
    picker.multiple = true;
    picker.hidden = true;
    picker.addEventListener('change', () => {
        Array.from(picker.files ?? []).forEach((file) => void upload(file));
        picker.value = '';
    });
    shell.append(picker);

    function remember(id: number): void {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'anhaenge_ids[]';
        input.value = String(id);
        input.dataset.anhang = String(id);
        shell.append(input);
    }

    async function upload(file: File): Promise<void> {
        if (!uploadUrl) {
            return;
        }
        announce(`${say('uploading', 'Lädt hoch …')} ${file.name}`);
        const data = new FormData();
        data.append('datei', file);
        data.append('_sprache', lang);
        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                body: data,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token(form),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const result = (await response.json()) as Uploaded & {
                message?: string;
            };
            if (!response.ok) {
                announce(
                    result.message ?? say('upload_failed', 'Fehlgeschlagen.'),
                );

                return;
            }
            remember(result.id);
            announce('');
            if (result.image) {
                editor
                    .chain()
                    .focus()
                    // Mit einem Absatz danach — sonst ersetzt das Nächste das noch markierte Bild
                    .insertContent([
                        {
                            type: 'image',
                            attrs: {
                                src: result.url,
                                alt: result.name,
                                attachment: result.id,
                            },
                        },
                        { type: 'paragraph' },
                    ])
                    .run();
            } else {
                listFile(result);
            }
        } catch {
            announce(say('upload_failed', 'Fehlgeschlagen.'));
        }
    }

    function listFile(file: Uploaded): void {
        const item = el('li', 'insel-editor__anhang', `📎 ${file.name}`);
        const remove = el('button', 'insel-editor__entfernen', '×');
        remove.type = 'button';
        remove.setAttribute(
            'aria-label',
            `${say('remove', 'Entfernen')}: ${file.name}`,
        );
        remove.addEventListener('click', () => {
            void fetch(`${uploadUrl}/${file.id}`, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token(form),
                },
                credentials: 'same-origin',
            });
            item.remove();
            shell.querySelector(`input[data-anhang="${file.id}"]`)?.remove();
        });
        item.append(' ', remove);
        files.append(item);
    }

    // ── Werkzeuge (TB 49) ───────────────────────────────────
    const remembered = area.dataset.inselZitat
        ? quotes(editor, area.dataset.inselZitat, say, shell)
        : null;
    const toolbar = buildToolbar(
        bar,
        buildTools({
            editor,
            say,
            pickFile: uploadUrl ? () => picker.click() : undefined,
            remembered: remembered?.count,
            insertRemembered: remembered?.insert,
            announce,
        }),
    );
    editor.on('selectionUpdate', () => toolbar.refresh());
    editor.on('transaction', () => toolbar.refresh());
    shell.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            toolbar.closeAll();
        }
    });

    // ── Reiter unter dem Editor: Smileys, Anhänge, Einstellungen, Umfrage, Vorschau ──
    buildPanels({
        editor,
        say,
        shell,
        form,
        upload: uploadUrl
            ? {
                  pick: () => picker.click(),
                  rules: area.dataset.inselRegeln ?? '',
              }
            : undefined,
        previewUrl: area.dataset.inselVorschau,
        token: () => token(form),
        lang,
    });

    // ── Wer schreibt gerade (TB 49) ─────────────────────────
    const typingConfig = readJson<TypingConfig | null>(
        area.dataset.inselSchreibt,
        null,
    );
    if (typingConfig?.url && typingConfig.key && typingConfig.token) {
        reportTyping(editor, typingConfig, form, () => token(form));
    }
    // Die Anzeige «… schreibt» des Servers sitzt in der Ecke des Textfelds
    const corner = form?.querySelector<HTMLElement>('[data-insel-ecke]');
    if (corner) {
        corner.classList.add('insel-editor__ecke');
        surface.after(corner);
    }

    // ── Erwähnungen ─────────────────────────────────────────
    const namesUrl = area.dataset.inselNamen;
    const list = el('ul', 'insel-editor__namen');
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    shell.append(list);
    let people: Person[] = [];
    let chosen = 0;
    let query: string | null = null;
    let asked = '';

    async function suggest(current: Editor): Promise<void> {
        if (!namesUrl) {
            return;
        }
        const { $from, empty } = current.state.selection;
        const before = empty
            ? $from.parent.textBetween(
                  Math.max(0, $from.parentOffset - 31),
                  $from.parentOffset,
                  undefined,
                  '￼',
              )
            : '';
        query = mentionQuery(before);
        if (query === null) {
            closeList();

            return;
        }
        if (query === asked) {
            return;
        }
        asked = query;
        try {
            const response = await fetch(
                `${namesUrl}?q=${encodeURIComponent(query)}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            people = response.ok ? ((await response.json()) as Person[]) : [];
        } catch {
            people = [];
        }
        chosen = 0;
        renderList();
    }

    function renderList(): void {
        list.replaceChildren();
        if (query === null) {
            closeList();

            return;
        }
        if (people.length === 0) {
            list.append(
                el(
                    'li',
                    'insel-editor__name insel-editor__name--leer',
                    say('mention_none', 'Niemand gefunden'),
                ),
            );
        }
        people.forEach((person, index) => {
            const item = el(
                'li',
                person.gruppe
                    ? 'insel-editor__name insel-editor__name--gruppe'
                    : 'insel-editor__name',
                `@${person.name}`,
            );
            if (person.gruppe) {
                item.append(
                    el(
                        'small',
                        'insel-editor__gruppe',
                        say('mention_group', 'Gruppe'),
                    ),
                );
            }
            item.setAttribute('role', 'option');
            item.setAttribute(
                'aria-selected',
                index === chosen ? 'true' : 'false',
            );
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(person);
            });
            list.append(item);
        });
        list.hidden = false;
    }

    function closeList(): void {
        list.hidden = true;
        people = [];
        asked = '';
    }

    function choose(person: Person): void {
        const length = (query ?? '').length + 1;
        const { from } = editor.state.selection;
        editor
            .chain()
            .focus()
            .deleteRange({ from: from - length, to: from })
            .insertContent([
                {
                    type: 'mention',
                    attrs: {
                        id: person.id,
                        label: person.name,
                        group: person.gruppe === true,
                    },
                },
                { type: 'text', text: ' ' },
            ])
            .run();
        closeList();
    }

    function mentionKey(event: KeyboardEvent): boolean {
        if (list.hidden || people.length === 0) {
            return false;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            chosen =
                (chosen + (event.key === 'ArrowDown' ? 1 : people.length - 1)) %
                people.length;
            renderList();

            return true;
        }
        if (event.key === 'Enter' || event.key === 'Tab') {
            choose(people[chosen]);

            return true;
        }
        if (event.key === 'Escape') {
            closeList();

            return true;
        }

        return false;
    }

    // ── Entwurf ─────────────────────────────────────────────
    let draftTimer: number | undefined;
    function saveDraft(current: Editor): void {
        if (draftKey === null) {
            return;
        }
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(() => {
            try {
                if (current.isEmpty) {
                    localStorage.removeItem(draftKey);
                } else {
                    localStorage.setItem(
                        draftKey,
                        JSON.stringify({
                            at: Date.now(),
                            doc: current.getJSON(),
                        }),
                    );
                }
            } catch {
                // Speicher voll oder gesperrt — der Entwurf ist ein Komfort, kein Muss
            }
        }, 800);
    }
    if (draftKey !== null && initial === '' && startDoc === null) {
        const stored = readJson<{ at?: number; doc?: JSONContent } | null>(
            localStorage.getItem(draftKey) ?? '',
            null,
        );
        if (
            stored?.doc &&
            typeof stored.at === 'number' &&
            Date.now() - stored.at < DRAFT_DAYS * 86400000
        ) {
            editor.commands.setContent(stored.doc);
            sync(editor);
            const discard = el(
                'button',
                'insel-editor__verwerfen',
                say('draft_discard', 'Verwerfen'),
            );
            discard.type = 'button';
            discard.addEventListener('click', () => {
                editor.commands.clearContent();
                sync(editor);
                localStorage.removeItem(draftKey);
                status.textContent = '';
            });
            status.textContent = `${say('draft_restored', 'Entwurf wiederhergestellt.')} `;
            status.append(discard);
        }
    }
    form?.addEventListener('submit', () => {
        window.clearTimeout(draftTimer);
        if (draftKey !== null) {
            localStorage.removeItem(draftKey);
        }
    });

    return editor;
}

const REMEMBERED = 'basalto-zitate';

/** «Zitieren» und «Merken» an den Beiträgen; gemerkte Zitate fügt eine Leiste über dem Editor gesammelt ein. */
function quotes(
    editor: Editor,
    urlTemplate: string,
    say: (key: string, fallback: string) => string,
    shell: HTMLElement,
): { count: () => number; insert: () => void } {
    const insert = async (id: string): Promise<void> => {
        const response = await fetch(
            urlTemplate.replace('{id}', encodeURIComponent(id)),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        if (!response.ok) {
            return;
        }
        const quote = (await response.json()) as {
            post: number;
            author: string;
            doc: JSONContent;
        };
        editor
            .chain()
            .focus('end')
            .insertContent([
                {
                    type: 'blockquote',
                    attrs: { author: quote.author, post: quote.post },
                    content: quote.doc.content ?? [],
                },
                { type: 'paragraph' },
            ])
            .run();
    };
    const remembered = (): string[] => {
        try {
            const value: unknown = JSON.parse(
                sessionStorage.getItem(REMEMBERED) ?? '[]',
            );

            return Array.isArray(value) ? value.map(String) : [];
        } catch {
            return [];
        }
    };
    const strip = el('div', 'insel-editor__zitate');
    const button = el('button', 'insel-editor__knopf-text');
    button.type = 'button';
    strip.append(button);
    shell.before(strip);
    const update = (): void => {
        const ids = remembered();
        strip.hidden = ids.length === 0;
        button.textContent = say(
            'quote_insert',
            ':count Zitate einfügen',
        ).replace(':count', String(ids.length));
    };
    const insertAll = async (): Promise<void> => {
        for (const id of remembered()) {
            await insert(id);
        }
        sessionStorage.removeItem(REMEMBERED);
        update();
    };
    button.addEventListener('click', () => void insertAll());
    document
        .querySelectorAll<HTMLButtonElement>('[data-forum-zitieren]')
        .forEach((item) => {
            item.hidden = false;
            item.addEventListener('click', async () => {
                await insert(item.dataset.forumZitieren ?? '');
                shell.scrollIntoView({ behavior: 'smooth', block: 'center' });
                (item.querySelector('[data-label]') ?? item).textContent = say(
                    'quoted',
                    'Zitat übernommen',
                );
            });
        });
    document
        .querySelectorAll<HTMLButtonElement>('[data-forum-merken]')
        .forEach((item) => {
            item.hidden = false;
            item.addEventListener('click', () => {
                const ids = remembered();
                const id = item.dataset.forumMerken ?? '';
                if (!ids.includes(id)) {
                    ids.push(id);
                    sessionStorage.setItem(REMEMBERED, JSON.stringify(ids));
                }
                (item.querySelector('[data-label]') ?? item).textContent = say(
                    'remembered',
                    'Gemerkt',
                );
                item.setAttribute('aria-pressed', 'true');
                update();
            });
        });
    update();

    return { count: () => remembered().length, insert: () => void insertAll() };
}
