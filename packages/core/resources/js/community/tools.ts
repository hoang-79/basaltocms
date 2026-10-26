import type { Editor } from '@tiptap/core';
import { CODE_LANGUAGES } from './code';
import { EMOJIS, embedFrom } from './embed';
import { icon } from './icons';
import { HIGHLIGHT_COLORS } from './nodes';
import { type ToolButton, type ToolItem, escapeHtml, menuOf } from './toolbar';

/**
 * Die Werkzeuge der Editor-Insel (TB 49) nach dem Vorbild von WoltLab: Rückgängig/Wiederholen; Fett, Kursiv, Emoji,
 * Link und «…» mit Absatzformat, Unterstreichen, Durchstreichen, Markierstift, Hoch- und Tiefstellen, Format
 * entfernen; Listen mit Einzug; Ausrichtung; Codeblock mit Sprache, erster Zeilennummer und Dateiname; Code im Text;
 * Tabelle mit Rasterwahl und Bearbeiten; Zitat mit gemerkten Zitaten; Spoiler mit Titel; Video; Datei.
 */
export type Say = (key: string, fallback: string) => string;

export interface ToolContext {
    editor: Editor;
    say: Say;
    /** Datei wählen — nur, wenn Anhänge erlaubt sind. */
    pickFile?: () => void;
    /** Anzahl gemerkter Zitate und Einfügen — nur, wenn Zitate erlaubt sind. */
    remembered?: () => number;
    insertRemembered?: () => void;
    announce: (message: string) => void;
}

const MAC =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const MOD = MAC ? '⌘' : 'Strg+';

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

/** Ein kleines Formular im Menü: Felder untereinander, Knöpfe rechts; Enter bestätigt. */
function form(
    title: string,
    fields: HTMLElement[],
    actions: HTMLButtonElement[],
    submit: () => void,
): HTMLElement {
    const wrapper = el('form', 'insel-menue__formular');
    wrapper.setAttribute('aria-label', title);
    wrapper.noValidate = true;
    wrapper.append(...fields);
    const row = el('div', 'insel-menue__aktionen');
    row.append(...actions);
    wrapper.append(row);
    wrapper.addEventListener('submit', (event) => {
        event.preventDefault();
        submit();
    });
    queueMicrotask(() =>
        wrapper.querySelector<HTMLElement>('input, select')?.focus(),
    );

    return wrapper;
}

let fieldId = 0;
function field(
    label: string,
    control: HTMLInputElement | HTMLSelectElement,
    hint = '',
): HTMLElement {
    const id = `insel-feld-${++fieldId}`;
    control.id = id;
    const wrapper = el('div', 'insel-menue__feld');
    const caption = el('label', '', label);
    caption.htmlFor = id;
    wrapper.append(caption, control);
    if (hint !== '') {
        const small = el('small', '', hint);
        small.id = `${id}-hilfe`;
        control.setAttribute('aria-describedby', small.id);
        wrapper.append(small);
    }

    return wrapper;
}

function input(type: string, value = '', placeholder = ''): HTMLInputElement {
    const node = el('input', 'insel-menue__eingabe');
    node.type = type;
    node.value = value;
    node.placeholder = placeholder;

    return node;
}

function action(
    label: string,
    primary = false,
    type: 'button' | 'submit' = 'button',
): HTMLButtonElement {
    const node = el(
        'button',
        primary
            ? 'insel-menue__knopf insel-menue__knopf--haupt'
            : 'insel-menue__knopf',
        label,
    );
    node.type = type;

    return node;
}

/** Adresse aus einer Eingabe: «example.ch» bekommt https://, sonst nur Web, Mail, Pfade und Anker. */
export function normalizeHref(value: string): string | null {
    const href = value.trim();
    if (href === '') {
        return null;
    }
    if (/^(https?:\/\/|mailto:|\/(?!\/)|#)/i.test(href)) {
        return href;
    }
    if (/^[\w-]+(\.[\w-]+)+(\/\S*)?$/.test(href)) {
        return `https://${href}`;
    }

    return null;
}

export function buildTools(context: ToolContext): ToolItem[] {
    const { editor, say } = context;
    const chain = () => editor.chain().focus();
    /** Menü schliessen und den Fokus sofort in den Text — sonst gingen die ersten Tasten danach verloren. */
    const done = (close: (refocus?: boolean) => void): void => {
        close(false);
        editor.view.focus();
    };

    const formats: ToolButton[] = [
        {
            key: 'underline',
            icon: 'underline',
            label: say('underline', 'Unterstrichen'),
            shortcut: `${MOD}U`,
            run: () => chain().toggleUnderline().run(),
            active: () => editor.isActive('underline'),
        },
        {
            key: 'strike',
            icon: 'strike',
            label: say('strike', 'Durchgestrichen'),
            run: () => chain().toggleStrike().run(),
            active: () => editor.isActive('strike'),
        },
        {
            key: 'subscript',
            icon: 'subscript',
            label: say('subscript', 'Tiefgestellt'),
            run: () => chain().unsetSuperscript().toggleSubscript().run(),
            active: () => editor.isActive('subscript'),
        },
        {
            key: 'superscript',
            icon: 'superscript',
            label: say('superscript', 'Hochgestellt'),
            run: () => chain().unsetSubscript().toggleSuperscript().run(),
            active: () => editor.isActive('superscript'),
        },
        {
            key: 'clear',
            icon: 'clear',
            label: say('clear', 'Format entfernen'),
            run: () => chain().unsetAllMarks().clearNodes().run(),
        },
    ];

    const moreFormats = (close: (refocus?: boolean) => void): HTMLElement => {
        const wrapper = el('div', 'insel-menue__mehr');
        const block = el('select', 'insel-menue__auswahl');
        block.setAttribute('aria-label', say('block_type', 'Absatzformat'));
        const levels: [string, string][] = [
            ['0', say('paragraph', 'Absatz')],
            ['2', say('heading1', 'Überschrift 1')],
            ['3', say('heading2', 'Überschrift 2')],
            ['4', say('heading3', 'Überschrift 3')],
        ];
        for (const [value, label] of levels) {
            const option = el('option', '', label);
            option.value = value;
            block.append(option);
        }
        block.value = String(
            [2, 3, 4].find((level) => editor.isActive('heading', { level })) ??
                0,
        );
        block.addEventListener('change', () => {
            const level = Number(block.value) as 0 | 2 | 3 | 4;
            if (level === 0) {
                chain().setParagraph().run();
            } else {
                chain().setHeading({ level }).run();
            }
            close(true);
        });
        const row = el('div', 'insel-menue__zeile');
        row.append(
            block,
            menuOf(formats, close, {
                label: say('more', 'Weitere Formate'),
                horizontal: true,
            }),
        );
        const colors = HIGHLIGHT_COLORS.map((color) => ({
            key: `highlight-${color}`,
            icon: 'highlight',
            label: say(`highlight_${color}`, `Markieren: ${color}`),
            run: () => chain().setMark('highlight', { color }).run(),
            active: () => editor.isActive('highlight', { color }),
        }));
        colors.push({
            key: 'highlight-none',
            icon: 'clear',
            label: say('highlight_none', 'Markierung entfernen'),
            run: () => chain().unsetMark('highlight').run(),
            active: () => false,
        });
        const swatches = menuOf(colors, close, {
            label: say('highlight', 'Markieren'),
            horizontal: true,
            radio: true,
        });
        swatches.classList.add('insel-menue__farben');
        const caption = el(
            'p',
            'insel-menue__titel',
            say('highlight', 'Markieren'),
        );
        wrapper.append(row, caption, swatches);
        queueMicrotask(() => block.focus());

        return wrapper;
    };

    const linkForm = (close: (refocus?: boolean) => void): HTMLElement => {
        const current =
            (editor.getAttributes('link').href as string | undefined) ?? '';
        const address = input('url', current, 'https://');
        const { empty } = editor.state.selection;
        const text = input('text', '', say('link_text_hint', 'leer = Adresse'));
        const error = el('p', 'insel-menue__fehler');
        error.setAttribute('role', 'alert');
        const fields = [field(say('link_address', 'Adresse'), address)];
        if (empty && !editor.isActive('link')) {
            fields.push(field(say('link_text', 'Text'), text));
        }
        fields.push(error);
        const actions = [action(say('cancel', 'Abbrechen'))];
        actions[0].addEventListener('click', () => close(true));
        if (current !== '') {
            const remove = action(say('link_remove', 'Link entfernen'));
            remove.addEventListener('click', () => {
                chain().extendMarkRange('link').unsetLink().run();
                done(close);
            });
            actions.push(remove);
        }
        actions.push(action(say('apply', 'Übernehmen'), true, 'submit'));

        return form(say('link', 'Link'), fields, actions, () => {
            const href = normalizeHref(address.value);
            if (href === null) {
                error.textContent = say(
                    'link_bad',
                    'Bitte eine Adresse mit https://, mailto: oder / eingeben.',
                );
                address.focus();

                return;
            }
            if (empty && !editor.isActive('link')) {
                const label = text.value.trim() || href;
                chain()
                    .insertContent({
                        type: 'text',
                        text: label,
                        marks: [{ type: 'link', attrs: { href } }],
                    })
                    .insertContent(' ')
                    .run();
            } else {
                chain().extendMarkRange('link').setLink({ href }).run();
            }
            done(close);
        });
    };

    const codeForm = (close: (refocus?: boolean) => void): HTMLElement => {
        const inBlock = editor.isActive('codeBlock');
        const attrs = (inBlock ? editor.getAttributes('codeBlock') : {}) as {
            language?: string | null;
            filename?: string | null;
            lineStart?: number | null;
        };
        const language = el('select', 'insel-menue__eingabe');
        const options: [string, string][] = [
            ['', say('code_auto', '(Automatische Erkennung)')],
            ['plaintext', say('code_none', '(Kein)')],
            ...CODE_LANGUAGES.map((l): [string, string] => [l.value, l.label]),
        ];
        for (const [value, label] of options) {
            const option = el('option', '', label);
            option.value = value;
            language.append(option);
        }
        language.value = attrs.language ?? '';
        const line = input(
            'number',
            attrs.lineStart ? String(attrs.lineStart) : '',
        );
        line.min = '1';
        line.inputMode = 'numeric';
        const file = input('text', attrs.filename ?? '', 'app/Http/Kernel.php');
        file.maxLength = 120;
        const cancel = action(say('cancel', 'Abbrechen'));
        cancel.addEventListener('click', () => close(true));

        return form(
            say('codeblock', 'Codeblock'),
            [
                field(say('code_language', 'Sprache'), language),
                field(
                    say('code_line', 'Zeilennummer'),
                    line,
                    say('code_line_hint', 'Nummer der ersten Zeile, leer = 1'),
                ),
                field(say('code_file', 'Dateiname'), file),
            ],
            [
                cancel,
                action(
                    inBlock
                        ? say('apply', 'Übernehmen')
                        : say('insert', 'Einfügen'),
                    true,
                    'submit',
                ),
            ],
            () => {
                const start = Number(line.value);
                const values = {
                    language: language.value === '' ? null : language.value,
                    filename:
                        file.value.trim() === '' ? null : file.value.trim(),
                    lineStart:
                        Number.isInteger(start) && start > 1 ? start : null,
                };
                if (inBlock) {
                    chain().updateAttributes('codeBlock', values).run();
                } else {
                    chain()
                        .setCodeBlock({ language: values.language ?? '' })
                        .updateAttributes('codeBlock', values)
                        .run();
                }
                done(close);
            },
        );
    };

    const tablePanel = (close: (refocus?: boolean) => void): HTMLElement => {
        if (editor.isActive('table')) {
            return menuOf(
                [
                    {
                        key: 'row-before',
                        icon: 'row-add',
                        label: say('row_before', 'Zeile darüber'),
                        run: () => chain().addRowBefore().run(),
                    },
                    {
                        key: 'row-after',
                        icon: 'row-add',
                        label: say('row_after', 'Zeile darunter'),
                        run: () => chain().addRowAfter().run(),
                    },
                    {
                        key: 'col-before',
                        icon: 'col-add',
                        label: say('col_before', 'Spalte links'),
                        run: () => chain().addColumnBefore().run(),
                    },
                    {
                        key: 'col-after',
                        icon: 'col-add',
                        label: say('col_after', 'Spalte rechts'),
                        run: () => chain().addColumnAfter().run(),
                    },
                    {
                        key: 'row-delete',
                        icon: 'row-remove',
                        label: say('row_delete', 'Zeile löschen'),
                        run: () => chain().deleteRow().run(),
                    },
                    {
                        key: 'col-delete',
                        icon: 'col-remove',
                        label: say('col_delete', 'Spalte löschen'),
                        run: () => chain().deleteColumn().run(),
                    },
                    {
                        key: 'header',
                        icon: 'table-header',
                        label: say('header_row', 'Kopfzeile ein/aus'),
                        run: () => chain().toggleHeaderRow().run(),
                    },
                    {
                        key: 'table-delete',
                        icon: 'trash',
                        label: say('table_delete', 'Tabelle löschen'),
                        run: () => chain().deleteTable().run(),
                    },
                ],
                close,
                { label: say('table', 'Tabelle') },
            );
        }

        return tableGrid(say, (rows, cols) => {
            chain().insertTable({ rows, cols, withHeaderRow: true }).run();
            done(close);
        });
    };

    const quotePanel = (close: (refocus?: boolean) => void): HTMLElement => {
        const count = context.remembered?.() ?? 0;
        const items: ToolButton[] = [
            {
                key: 'quote',
                icon: 'quote',
                label: editor.isActive('blockquote')
                    ? say('quote_remove', 'Zitat aufheben')
                    : say('quote', 'Zitat'),
                run: () => chain().toggleBlockquote().run(),
            },
        ];
        if (context.insertRemembered) {
            const insert = context.insertRemembered;
            items.push({
                key: 'remembered',
                icon: 'plus',
                label: say('quote_insert', ':count Zitate einfügen').replace(
                    ':count',
                    String(count),
                ),
                run: () => insert(),
                enabled: () => count > 0,
            });
        }

        return menuOf(items, close, { label: say('quote', 'Zitat') });
    };

    const spoilerForm = (close: (refocus?: boolean) => void): HTMLElement => {
        const title = input('text', '', say('spoiler', 'Spoiler'));
        title.maxLength = 60;
        const cancel = action(say('cancel', 'Abbrechen'));
        cancel.addEventListener('click', () => close(true));

        return form(
            say('spoiler', 'Spoiler'),
            [
                field(
                    say('spoiler_title', 'Titel'),
                    title,
                    say('spoiler_hint', 'leer = «Spoiler»'),
                ),
            ],
            [cancel, action(say('insert', 'Einfügen'), true, 'submit')],
            () => {
                chain()
                    .wrapIn('spoiler', { title: title.value.trim() || null })
                    .run();
                done(close);
            },
        );
    };

    const videoForm = (close: (refocus?: boolean) => void): HTMLElement => {
        const address = input('url', '', 'https://www.youtube.com/watch?v=…');
        const error = el('p', 'insel-menue__fehler');
        error.setAttribute('role', 'alert');
        const cancel = action(say('cancel', 'Abbrechen'));
        cancel.addEventListener('click', () => close(true));

        return form(
            say('video', 'Video einbetten'),
            [
                field(
                    say('video_address', 'Adresse des Videos'),
                    address,
                    say(
                        'video_hint',
                        'YouTube oder Vimeo — geladen erst nach Klick',
                    ),
                ),
                error,
            ],
            [cancel, action(say('insert', 'Einfügen'), true, 'submit')],
            () => {
                const embed = embedFrom(address.value);
                if (embed === null) {
                    error.textContent = say(
                        'video_bad',
                        'Nur YouTube oder Vimeo.',
                    );

                    return;
                }
                chain()
                    .insertContent([
                        { type: 'embed', attrs: embed },
                        { type: 'paragraph' },
                    ])
                    .run();
                done(close);
            },
        );
    };

    const emojiPanel = (close: (refocus?: boolean) => void): HTMLElement =>
        emojiGrid(say, (emoji) => {
            chain().insertContent(emoji).run();
            done(close);
        });

    const items: ToolItem[] = [
        {
            key: 'undo',
            icon: 'undo',
            label: say('undo', 'Rückgängig'),
            shortcut: `${MOD}Z`,
            run: () => chain().undo().run(),
            enabled: () => editor.can().undo(),
        },
        {
            key: 'redo',
            icon: 'redo',
            label: say('redo', 'Wiederholen'),
            shortcut: MAC ? '⇧⌘Z' : 'Strg+Y',
            run: () => chain().redo().run(),
            enabled: () => editor.can().redo(),
        },
        { kind: 'separator' },
        {
            key: 'bold',
            icon: 'bold',
            label: say('bold', 'Fett'),
            shortcut: `${MOD}B`,
            run: () => chain().toggleBold().run(),
            active: () => editor.isActive('bold'),
        },
        {
            key: 'italic',
            icon: 'italic',
            label: say('italic', 'Kursiv'),
            shortcut: `${MOD}I`,
            run: () => chain().toggleItalic().run(),
            active: () => editor.isActive('italic'),
        },
        {
            kind: 'menu',
            key: 'emoji',
            icon: 'smile',
            label: say('emoji', 'Emoji'),
            panel: emojiPanel,
        },
        {
            kind: 'menu',
            key: 'link',
            icon: 'link',
            label: say('link', 'Link'),
            active: () => editor.isActive('link'),
            panel: linkForm,
        },
        {
            kind: 'menu',
            key: 'more',
            icon: 'more',
            label: say('more', 'Weitere Formate'),
            active: () =>
                [
                    'underline',
                    'strike',
                    'subscript',
                    'superscript',
                    'highlight',
                    'heading',
                ].some((name) => editor.isActive(name)),
            panel: moreFormats,
        },
        { kind: 'separator' },
        {
            kind: 'menu',
            key: 'lists',
            icon: 'list',
            label: say('lists', 'Listen'),
            active: () =>
                editor.isActive('bulletList') || editor.isActive('orderedList'),
            panel: (close) =>
                menuOf(
                    [
                        {
                            key: 'bullet',
                            icon: 'list',
                            label: say('list', 'Aufzählung'),
                            run: () => chain().toggleBulletList().run(),
                            active: () => editor.isActive('bulletList'),
                        },
                        {
                            key: 'ordered',
                            icon: 'ordered',
                            label: say('ordered', 'Nummerierte Liste'),
                            run: () => chain().toggleOrderedList().run(),
                            active: () => editor.isActive('orderedList'),
                        },
                        {
                            key: 'outdent',
                            icon: 'outdent',
                            label: say('outdent', 'Einzug verkleinern'),
                            run: () => chain().liftListItem('listItem').run(),
                            enabled: () =>
                                editor.can().liftListItem('listItem'),
                        },
                        {
                            key: 'indent',
                            icon: 'indent',
                            label: say('indent', 'Einzug vergrössern'),
                            run: () => chain().sinkListItem('listItem').run(),
                            enabled: () =>
                                editor.can().sinkListItem('listItem'),
                        },
                    ],
                    close,
                    { label: say('lists', 'Listen'), horizontal: true },
                ),
        },
        {
            kind: 'menu',
            key: 'align',
            icon: 'align-left',
            label: say('align', 'Ausrichtung'),
            active: () =>
                ['center', 'right', 'justify'].some((align) =>
                    editor.isActive({ textAlign: align }),
                ),
            panel: (close) =>
                menuOf(
                    (
                        [
                            [
                                'left',
                                'align-left',
                                say('align_left', 'Linksbündig'),
                            ],
                            [
                                'center',
                                'align-center',
                                say('align_center', 'Zentriert'),
                            ],
                            [
                                'right',
                                'align-right',
                                say('align_right', 'Rechtsbündig'),
                            ],
                            [
                                'justify',
                                'align-justify',
                                say('align_justify', 'Blocksatz'),
                            ],
                        ] as const
                    ).map(([align, iconName, label]) => ({
                        key: `align-${align}`,
                        icon: iconName,
                        label,
                        run: () =>
                            align === 'left'
                                ? chain().unsetTextAlign().run()
                                : chain().setTextAlign(align).run(),
                        active: () =>
                            align === 'left'
                                ? !['center', 'right', 'justify'].some((a) =>
                                      editor.isActive({ textAlign: a }),
                                  )
                                : editor.isActive({ textAlign: align }),
                    })),
                    close,
                    { label: say('align', 'Ausrichtung'), radio: true },
                ),
        },
        { kind: 'separator' },
        {
            kind: 'menu',
            key: 'codeBlock',
            icon: 'code-block',
            label: say('codeblock', 'Codeblock'),
            menuLabel: say(
                'codeblock_settings',
                'Sprache, Zeilennummer und Dateiname',
            ),
            run: () => chain().toggleCodeBlock().run(),
            active: () => editor.isActive('codeBlock'),
            panel: codeForm,
        },
        {
            key: 'code',
            icon: 'code',
            label: say('code', 'Code im Text'),
            shortcut: `${MOD}E`,
            run: () => chain().toggleCode().run(),
            active: () => editor.isActive('code'),
        },
        {
            kind: 'menu',
            key: 'table',
            icon: 'table',
            label: say('table', 'Tabelle'),
            active: () => editor.isActive('table'),
            panel: tablePanel,
        },
        {
            kind: 'menu',
            key: 'quote',
            icon: 'quote',
            label: say('quote', 'Zitat'),
            menuLabel: say('quotes', 'Zitate'),
            run: () => chain().toggleBlockquote().run(),
            active: () => editor.isActive('blockquote'),
            panel: quotePanel,
        },
        {
            kind: 'menu',
            key: 'spoiler',
            icon: 'spoiler',
            label: say('spoiler', 'Spoiler'),
            menuLabel: say('spoiler_titled', 'Spoiler mit Titel'),
            run: () =>
                editor.isActive('spoiler')
                    ? chain().lift('spoiler').run()
                    : chain().wrapIn('spoiler', { title: null }).run(),
            active: () => editor.isActive('spoiler'),
            panel: spoilerForm,
        },
        {
            kind: 'menu',
            key: 'video',
            icon: 'video',
            label: say('video', 'Video einbetten'),
            panel: videoForm,
        },
    ];
    if (context.pickFile) {
        const pick = context.pickFile;
        items.push({
            key: 'file',
            icon: 'paperclip',
            label: say('file', 'Datei oder Bild anhängen'),
            run: () => pick(),
        });
    }

    return items;
}

/** Raster zum Wählen der Tabellengrösse — Maus oder Pfeiltasten, Enter fügt ein. */
export function tableGrid(
    say: Say,
    insert: (rows: number, cols: number) => void,
    size = { rows: 8, cols: 10 },
): HTMLElement {
    const wrapper = el('div', 'insel-raster');
    const grid = el('div', 'insel-raster__felder');
    grid.tabIndex = 0;
    grid.setAttribute('role', 'slider');
    grid.setAttribute('aria-valuemin', '1');
    grid.setAttribute('aria-valuemax', String(size.rows * size.cols));
    grid.style.gridTemplateColumns = `repeat(${size.cols}, 1fr)`;
    const label = el('p', 'insel-raster__groesse');
    label.setAttribute('aria-hidden', 'true');
    let rows = 3;
    let cols = 3;
    const cells: HTMLElement[] = [];
    const paint = (): void => {
        cells.forEach((cell, index) => {
            cell.classList.toggle(
                'ist-gewaehlt',
                Math.floor(index / size.cols) < rows &&
                    index % size.cols < cols,
            );
        });
        const text = `${rows} × ${cols}`;
        label.textContent = text;
        grid.setAttribute('aria-valuenow', String(rows * size.cols + cols));
        grid.setAttribute(
            'aria-valuetext',
            say('table_size', ':rows Zeilen, :cols Spalten')
                .replace(':rows', String(rows))
                .replace(':cols', String(cols)),
        );
    };
    for (let r = 1; r <= size.rows; r++) {
        for (let c = 1; c <= size.cols; c++) {
            const cell = el('span', 'insel-raster__feld');
            cell.addEventListener('mouseenter', () => {
                rows = r;
                cols = c;
                paint();
            });
            cell.addEventListener('mousedown', (event) =>
                event.preventDefault(),
            );
            cell.addEventListener('click', () => insert(r, c));
            cells.push(cell);
            grid.append(cell);
        }
    }
    grid.setAttribute('aria-label', say('table_insert', 'Tabelle einfügen'));
    grid.addEventListener('keydown', (event) => {
        const moves: Record<string, [number, number]> = {
            ArrowRight: [0, 1],
            ArrowLeft: [0, -1],
            ArrowDown: [1, 0],
            ArrowUp: [-1, 0],
        };
        const move = moves[event.key];
        if (move) {
            event.preventDefault();
            rows = Math.min(size.rows, Math.max(1, rows + move[0]));
            cols = Math.min(size.cols, Math.max(1, cols + move[1]));
            paint();
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            insert(rows, cols);
        }
    });
    paint();
    wrapper.append(grid, label);
    queueMicrotask(() => grid.focus());

    return wrapper;
}

/** Emojis als Raster — Pfeiltasten in beide Richtungen. */
export function emojiGrid(
    say: Say,
    pick: (emoji: string) => void,
    columns = 8,
    autofocus = true,
): HTMLElement {
    const grid = el('div', 'insel-emojis');
    grid.setAttribute('role', 'menu');
    grid.setAttribute('aria-label', say('emoji', 'Emoji'));
    grid.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
    const buttons = EMOJIS.map((emoji) => {
        const button = el('button', 'insel-emojis__emoji', emoji);
        button.type = 'button';
        button.tabIndex = -1;
        button.setAttribute('role', 'menuitem');
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => pick(emoji));
        grid.append(button);

        return button;
    });
    grid.addEventListener('keydown', (event) => {
        const index = buttons.indexOf(
            document.activeElement as HTMLButtonElement,
        );
        const moves: Record<string, number> = {
            ArrowRight: 1,
            ArrowLeft: -1,
            ArrowDown: columns,
            ArrowUp: -columns,
        };
        if (index >= 0 && event.key in moves) {
            event.preventDefault();
            buttons[
                (index + moves[event.key] + buttons.length) % buttons.length
            ].focus();
        }
    });
    if (autofocus) {
        queueMicrotask(() => buttons[0]?.focus());
    }

    return grid;
}

export { escapeHtml, icon };
