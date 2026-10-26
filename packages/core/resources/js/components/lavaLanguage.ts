import { StreamLanguage } from '@codemirror/language';
import type { StringStream } from '@codemirror/language';

/**
 * Hervorhebung für Lava in CodeMirror (L-17): Kommentare, Zeichenketten, Schlüsselwörter der Stationen, Markup
 * zwischen ``` mit {{ Ausgaben }} und {% Tags %}. Bewusst schlicht — die Prüfung macht der Compiler.
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

type State = { markup: boolean; inTag: boolean };

function token(stream: StringStream, state: State): string | null {
    if (stream.sol() && stream.match(/^\s*```/)) {
        state.markup = !state.markup;

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
    if (stream.peek() === '#') {
        stream.skipToEnd();

        return 'comment';
    }
    if (stream.match(/^"(?:[^"\\]|\\.)*"?/) || stream.match(/^«[^»]*»?/)) {
        return 'string';
    }
    if (
        stream.match(/^#[0-9a-fA-F]{3,8}\b/) ||
        stream.match(/^-?\d+(\.\d+)?/)
    ) {
        return 'number';
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

export const lava = StreamLanguage.define<State>({
    name: 'lava',
    startState: () => ({ markup: false, inTag: false }),
    token,
    languageData: { commentTokens: { line: '#' } },
});
