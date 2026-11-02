import { foldService, matchBrackets } from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { t } from '../i18n';
import { lava } from './lavaLanguage';

/**
 * Sprache je Datei des Theme-Editors (TB 97): Lava selbst, dazu CSS, HTML (auch Teile `.lava.html` und SVG), JSON
 * und JavaScript über die CodeMirror-Pakete — erst geladen, wenn eine solche Datei offen ist. Markdown und Text bleiben
 * ohne Hervorhebung.
 */
export type CodeKind = 'lava' | 'css' | 'html' | 'svg' | 'json' | 'js' | 'text';

export function kindOf(path: string): CodeKind {
    const name = path.toLowerCase();
    if (name.endsWith('.lava')) {
        return 'lava';
    }
    if (name.endsWith('.css')) {
        return 'css';
    }
    if (name.endsWith('.html')) {
        return 'html';
    }
    if (name.endsWith('.svg')) {
        return 'svg';
    }
    if (name.endsWith('.json')) {
        return 'json';
    }
    if (name.endsWith('.js') || name.endsWith('.mjs')) {
        return 'js';
    }

    return 'text';
}

/** Name der Sprache für die Statuszeile. */
export function kindLabel(kind: CodeKind): string {
    return {
        lava: 'Lava',
        css: 'CSS',
        html: 'HTML',
        svg: 'SVG',
        json: 'JSON',
        js: 'JavaScript',
        text: t('Text'),
    }[kind];
}

/** Lava einklappen: von einer Zeile, die mit «{» endet, bis zur passenden «}». */
const lavaFolding = foldService.of((state, lineStart, lineEnd) => {
    const line = state.doc.lineAt(lineStart);
    const brace = line.text.search(/\{\s*(#.*)?$/);
    if (brace < 0) {
        return null;
    }
    const match = matchBrackets(state, line.from + brace, 1);
    if (!match?.matched || !match.end || match.end.from <= lineEnd) {
        return null;
    }

    return { from: line.from + brace + 1, to: match.end.from };
});

/** Lava sofort (ohne Nachladen), damit der Text nicht erst ungefärbt erscheint; andere Sprachen: null. */
export function languageNow(path: string): Extension | null {
    return kindOf(path) === 'lava' ? [lava, lavaFolding] : null;
}

/** Die Erweiterungen der Sprache einer Datei (Hervorhebung, Einrückung, Einklappen, Kommentarzeichen). */
export async function languageFor(path: string): Promise<Extension> {
    switch (kindOf(path)) {
        case 'lava':
            return languageNow(path) ?? [];
        case 'css':
            return (await import('@codemirror/lang-css')).css();
        case 'html':
        case 'svg':
            return (await import('@codemirror/lang-html')).html({
                autoCloseTags: true,
            });
        case 'json':
            return (await import('@codemirror/lang-json')).json();
        case 'js':
            return (await import('@codemirror/lang-javascript')).javascript();
        default:
            return [];
    }
}
