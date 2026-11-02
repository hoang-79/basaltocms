import { StreamLanguage } from '@codemirror/language';
import type { IndentContext, StringStream } from '@codemirror/language';

/**
 * Hervorhebung für Lava in CodeMirror (L-17): Kommentare, Zeichenketten, Schlüsselwörter der Stationen, Markup
 * zwischen ``` mit {{ Ausgaben }} und {% Tags %}. Bewusst schlicht — die Prüfung macht der Compiler.
 *
 * Einrückung nach der Tiefe der geschweiften Klammern (zwei Leerzeichen wie der Lava-Drucker); Markup bleibt, wie es
 * ist. Daraus rechnen «Formatieren» und die Einrückung beim Tippen.
 */
const KEYWORDS = new Set([
    'site',
    'design',
    'layout',
    'part',
    'nav',
    'type',
    'global',
    'block',
    'template',
    'form',
    'seed',
    'publish',
    'include',
    'lava',
    'field',
    'region',
    'item',
    'markup',
    'color',
    'font',
    'page',
    'extends',
    'variant',
    'images',
]);

type State = { markup: boolean; inTag: boolean; depth: number };

/** Hex-Farbe wie im Lexer (`isColorAhead`): «#» mit 3–8 Hex-Ziffern ist eine Farbe, kein Kommentar. */
const COLOR = /^#[0-9a-fA-F]{3,8}(?![0-9a-zA-Z])/;

function token(stream: StringStream, state: State): string | null {
    // Wie der Lexer: ``` öffnet Markup an beliebiger Stelle («markup ```»), das nächste ``` schliesst es
    if (stream.match('```')) {
        state.markup = !state.markup;
        state.inTag = false;

        return 'meta';
    }
    if (state.markup) {
        if (state.inTag) {
            if (stream.match('}}') || stream.match('%}')) {
                state.inTag = false;

                return 'bracket';
            }
            if (stream.match(/^"(?:[^"\\]|\\.)*"?/)) {
                return 'string';
            }
            if (
                stream.match(
                    /^(if|else|elseif|end|for|in|and|or|not|raw|link|image|form|search|part|region|nav)\b/,
                )
            ) {
                return 'keyword';
            }
            if (stream.match(/^\|\s*[\w-]+/)) {
                return 'operator';
            }
            stream.next();

            return 'variableName';
        }
        if (stream.match('{{') || stream.match('{%')) {
            state.inTag = true;

            return 'bracket';
        }
        if (stream.match(/^<\/?[\w-]+/) || stream.match(/^\/?>/)) {
            return 'tagName';
        }
        stream.next();

        return null;
    }
    if (stream.eatSpace()) {
        return null;
    }
    if (stream.match(COLOR)) {
        return 'number';
    }
    if (stream.peek() === '#') {
        stream.skipToEnd();

        return 'comment';
    }
    if (stream.match(/^"(?:[^"\\]|\\.)*"?/) || stream.match(/^«[^»]*»?/)) {
        return 'string';
    }
    if (stream.match(/^-?\d+(\.\d+)?/)) {
        return 'number';
    }
    if (stream.eat('{')) {
        state.depth++;

        return 'brace';
    }
    if (stream.eat('}')) {
        state.depth = Math.max(0, state.depth - 1);

        return 'brace';
    }
    if (stream.match('->')) {
        return 'operator';
    }
    const word = stream.match(/^[\w-]+(\.[a-z]{2})?/);
    if (word && Array.isArray(word)) {
        if (KEYWORDS.has(word[0])) {
            return 'keyword';
        }
        if (stream.peek() === ':') {
            return 'propertyName';
        }

        return 'variableName';
    }
    stream.next();

    return null;
}

/** Einzug einer Zeile: Tiefe der offenen Klammern, eine weniger vor «}»; im Markup keiner (bleibt, wie es ist). */
function indent(
    state: State,
    textAfter: string,
    context: IndentContext,
): number | null {
    if (state.markup) {
        return null;
    }
    const closing = /^\s*\}/.test(textAfter) ? 1 : 0;

    return Math.max(0, state.depth - closing) * context.unit;
}

export const lava = StreamLanguage.define<State>({
    name: 'lava',
    startState: () => ({ markup: false, inTag: false, depth: 0 }),
    copyState: (state) => ({ ...state }),
    token,
    indent,
    languageData: {
        commentTokens: { line: '#' },
        indentOnInput: /^\s*\}$/,
    },
});
