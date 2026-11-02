import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { foldService, matchBrackets } from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { t } from '../i18n';
import { lava } from './lavaLanguage';

/**
 * Sprache je Datei des Theme-Editors (TB 97): Lava selbst, dazu CSS, HTML (auch Teile `.lava.html` und SVG), JSON
 * und JavaScript über die CodeMirror-Pakete. Fest eingebunden, nicht nachgeladen: Ein dynamischer Import aus
 * node_modules schreibt beim Release-Bau (node_modules verlinkt) den Pfad des Rechners ins öffentliche Vite-Manifest
 * (v1.3.23, vom Geheimnis-Scan gestoppt). Die Pakete landen so im Bündel der Seite Theme-Editor. Markdown und Text
 * bleiben ohne Hervorhebung.
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

/** Die Erweiterungen der Sprache einer Datei (Hervorhebung, Einrückung, Einklappen, Kommentarzeichen). */
export function languageFor(path: string): Extension {
    switch (kindOf(path)) {
        case 'lava':
            return [lava, lavaFolding];
        case 'css':
            return css();
        case 'html':
        case 'svg':
            return html({ autoCloseTags: true });
        case 'json':
            return json();
        case 'js':
            return javascript();
        default:
            return [];
    }
}
