/**
 * Ein kleiner Markdown-Parser für das Einfügen (M-04): Überschriften, Listen (auch Aufgaben und verschachtelt),
 * Zitate, Codeblöcke, Trennlinie, Fett/Kursiv/Durchgestrichen/Code, Links und Bilder — ohne Bibliothek.
 * Das Ergebnis ist HTML, das der Editor über sein Schema einliest.
 */

/** Sieht der Text nach Markdown aus? Blockzeichen am Zeilenanfang oder Fett-/Code-Auszeichnung. */
export function isMarkdown(text: string): boolean {
    const lines = text.split(/\r?\n/);
    const blockStart =
        /^(#{1,6}\s|[-*+]\s|\d+[.)]\s|>\s?|```|---\s*$|\*\*\*\s*$)/;
    const marked = lines.filter((line) => blockStart.test(line.trim())).length;
    if (marked > 0) {
        return true;
    }

    return /\*\*[^*\n]+\*\*|`[^`\n]+`|\[[^\]\n]+\]\([^)\n]+\)/.test(text);
}

function escape(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Inline-Auszeichnungen: Code zuerst (sein Inhalt bleibt roh), dann Bilder, Links, Fett, Kursiv, Durchgestrichen. */
export function inlineMarkdown(text: string): string {
    const codes: string[] = [];
    let out = escape(text).replace(/`([^`]+)`/g, (_m, code: string) => {
        codes.push(`<code>${code}</code>`);

        return `\uE000${codes.length - 1}\uE000`;
    });
    out = out
        .replace(
            /!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/g,
            '<img src="$2" alt="$1">',
        )
        .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2">$1</a>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/(^|[^*\w])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/(^|[^_\w])_([^_\n]+)_(?!\w)/g, '$1<em>$2</em>')
        .replace(/~~([^~]+)~~/g, '<s>$1</s>');

    return out.replace(
        /\uE000(\d+)\uE000/g,
        (_m, i: string) => codes[Number(i)] ?? '',
    );
}

type ListFrame = { type: 'ul' | 'ol' | 'task'; indent: number };

function listTag(type: ListFrame['type']): string {
    return type === 'ol'
        ? 'ol'
        : type === 'task'
          ? 'ul data-type="taskList"'
          : 'ul';
}

function closeTag(type: ListFrame['type']): string {
    return type === 'ol' ? '</ol>' : '</ul>';
}

export function markdownToHtml(text: string): string {
    const lines = text.replace(/\r\n?/g, '\n').split('\n');
    const html: string[] = [];
    const lists: ListFrame[] = [];
    let paragraph: string[] = [];
    let quote: string[] = [];
    let code: { lang: string; lines: string[] } | null = null;

    const flushParagraph = (): void => {
        if (paragraph.length) {
            const inline = inlineMarkdown(paragraph.join(' '));
            html.push(
                /^<img [^>]+>$/.test(inline) ? inline : `<p>${inline}</p>`,
            );
            paragraph = [];
        }
    };
    const closeLists = (toIndent = -1): void => {
        while (
            lists.length &&
            (lists[lists.length - 1]?.indent ?? 0) > toIndent
        ) {
            const frame = lists.pop();
            if (frame) {
                html.push(`</li>${closeTag(frame.type)}`);
            }
        }
    };
    const flushQuote = (): void => {
        if (quote.length) {
            html.push(
                `<blockquote>${markdownToHtml(quote.join('\n'))}</blockquote>`,
            );
            quote = [];
        }
    };

    for (const raw of lines) {
        if (code) {
            if (/^\s*```/.test(raw)) {
                const lang = code.lang
                    ? ` class="language-${escape(code.lang)}"`
                    : '';
                html.push(
                    `<pre><code${lang}>${escape(code.lines.join('\n'))}</code></pre>`,
                );
                code = null;
            } else {
                code.lines.push(raw);
            }
            continue;
        }
        const fence = /^\s*```\s*([\w-]*)\s*$/.exec(raw);
        if (fence) {
            flushParagraph();
            closeLists();
            flushQuote();
            code = { lang: fence[1] ?? '', lines: [] };
            continue;
        }
        const line = raw.replace(/\s+$/, '');
        if (line.trim() === '') {
            flushParagraph();
            flushQuote();
            continue;
        }
        const quoteMatch = /^\s*>\s?(.*)$/.exec(line);
        if (quoteMatch) {
            flushParagraph();
            closeLists();
            quote.push(quoteMatch[1] ?? '');
            continue;
        }
        flushQuote();
        const heading = /^(#{1,6})\s+(.*?)\s*#*$/.exec(line);
        if (heading) {
            flushParagraph();
            closeLists();
            const level = Math.min(4, Math.max(2, (heading[1] ?? '').length));
            html.push(
                `<h${level}>${inlineMarkdown(heading[2] ?? '')}</h${level}>`,
            );
            continue;
        }
        if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
            flushParagraph();
            closeLists();
            html.push('<hr>');
            continue;
        }
        const item = /^(\s*)([-*+]|\d+[.)])\s+(?:\[([ xX])\]\s+)?(.*)$/.exec(
            line,
        );
        if (item) {
            flushParagraph();
            const indent = (item[1] ?? '').length;
            const checked = item[3] !== undefined;
            const type: ListFrame['type'] = checked
                ? 'task'
                : /\d/.test(item[2] ?? '')
                  ? 'ol'
                  : 'ul';
            const top = lists[lists.length - 1];
            if (!top || indent > top.indent) {
                lists.push({ type, indent });
                html.push(`<${listTag(type)}>`);
            } else {
                closeLists(indent);
                const current = lists[lists.length - 1];
                if (
                    current &&
                    current.indent === indent &&
                    current.type !== type
                ) {
                    closeLists(indent - 1);
                }
                const same = lists[lists.length - 1];
                if (same && same.indent === indent) {
                    html.push('</li>');
                } else {
                    lists.push({ type, indent });
                    html.push(`<${listTag(type)}>`);
                }
            }
            const content = inlineMarkdown(item[4] ?? '');
            if (checked) {
                const done = (item[3] ?? ' ').toLowerCase() === 'x';
                html.push(
                    `<li data-type="taskItem" data-checked="${done ? 'true' : 'false'}"><p>${content}</p>`,
                );
            } else {
                html.push(`<li><p>${content}</p>`);
            }
            continue;
        }
        if (lists.length && /^\s{2,}\S/.test(line)) {
            html.push(`<p>${inlineMarkdown(line.trim())}</p>`);
            continue;
        }
        closeLists();
        paragraph.push(line.trim());
    }
    if (code) {
        const open = code as { lang: string; lines: string[] };
        html.push(`<pre><code>${escape(open.lines.join('\n'))}</code></pre>`);
    }
    flushParagraph();
    closeLists();
    flushQuote();

    return html.join('');
}
