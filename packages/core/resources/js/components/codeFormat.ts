import {
    ensureSyntaxTree,
    getIndentation,
    indentString,
    indentUnit,
    syntaxTree,
} from '@codemirror/language';
import { ChangeSet } from '@codemirror/state';
import type { ChangeSpec, EditorState, Text } from '@codemirror/state';
import { t } from '../i18n';
import type { CodeKind } from './codeLanguage';

/**
 * «Formatieren» im Code-Editor (TB 97) — so, dass gut geschriebene Dateien unverändert bleiben (geprüft an allen
 * Themes des Repos):
 *
 * - JSON wird vollständig neu geschrieben, mit der Einrückung der Datei.
 * - Lava, CSS und JavaScript: Die Sprache ordnet die Einrückung jeder Zeile neu (Lava nach Klammertiefe, CSS/JS nach
 *   ihrem Parser). Zeilen im Inneren eines Block-Kommentars (« * …») und Markup in Lava bleiben, wie sie sind.
 * - HTML und SVG nur aufräumen: Teile der Themes sind Lava-Vorlagen, kein reines HTML — deren Einrückung kennt der
 *   HTML-Parser nicht.
 *
 * Überall: Leerzeichen am Zeilenende weg und genau ein Zeilenumbruch am Ende. Kommentare bleiben — anders als beim
 * Lava-Drucker des Servers. Markdown und Text formatiert es nicht (zwei Leerzeichen am Zeilenende sind in Markdown ein
 * Zeilenumbruch).
 */
export type FormatResult = { changes: ChangeSpec | null; error: string };

/**
 * Die Einrückung, die eine Datei schon benutzt: der häufigste Sprung zwischen aufeinanderfolgenden eingerückten
 * Zeilen (2, 4 oder 8 Leerzeichen), Tabulatoren, wenn sie überwiegen — sonst zwei Leerzeichen wie der Lava-Drucker.
 * Formatieren behält so den Stil der Datei (eine CSS-Datei mit vier Leerzeichen bleibt bei vier).
 */
export function detectIndent(text: string): string {
    const steps = new Map<number, number>();
    let tabs = 0;
    let previous = 0;
    for (const line of text.split('\n')) {
        if (line.trim() === '') {
            continue;
        }
        if (line.startsWith('\t')) {
            tabs++;
            continue;
        }
        const spaces = line.length - line.trimStart().length;
        const step = Math.abs(spaces - previous);
        previous = spaces;
        if (step === 2 || step === 4 || step === 8) {
            steps.set(step, (steps.get(step) ?? 0) + 1);
        }
    }
    let best = 2;
    let bestCount = 0;
    for (const [step, count] of steps) {
        if (count > bestCount || (count === bestCount && step < best)) {
            best = step;
            bestCount = count;
        }
    }
    if (tabs > bestCount) {
        return '\t';
    }

    return ' '.repeat(best);
}

/**
 * Die Einrückung, mit der der Editor arbeitet: Lava immer zwei Leerzeichen wie der Lava-Drucker (eingerücktes Markup
 * soll die Struktur nicht umstellen), sonst die der Datei.
 */
export function indentFor(kind: CodeKind, text: string): string {
    return kind === 'lava' ? '  ' : detectIndent(text);
}

/** Die Einrückung für die Statuszeile: «2 Leerzeichen», «Tabulator». */
export function indentLabel(unit: string): string {
    return unit === '\t'
        ? t('Tabulator')
        : t(':count Leerzeichen', { count: unit.length });
}

/** Ob «Formatieren» für diese Art Datei etwas tut. */
export function formattable(kind: CodeKind): boolean {
    return kind !== 'text';
}

/** Ob die Sprache die Einrückung neu ordnen darf (siehe oben). */
function reindents(kind: CodeKind): boolean {
    return kind === 'lava' || kind === 'css' || kind === 'js';
}

/** Ein Kommentar beginnt in dieser Zeile — sonst steht sie im Inneren eines Block-Kommentars. */
const COMMENT_START = /^(#|\/\/|\/\*|<!--)/;

/** Die Einrückung jeder Zeile nach der Sprache — ohne leere Zeilen, das Innere von Block-Kommentaren und Markup. */
function reindent(state: EditorState): ChangeSet {
    const doc = state.doc;
    const tree = syntaxTree(state);
    const changes: { from: number; to: number; insert: string }[] = [];
    for (let n = 1; n <= doc.lines; n++) {
        const line = doc.line(n);
        const current = /^[ \t]*/.exec(line.text)?.[0] ?? '';
        const rest = line.text.slice(current.length);
        if (rest === '') {
            continue;
        }
        const node = tree.resolveInner(line.from + current.length, 1);
        if (/comment/i.test(node.name) && !COMMENT_START.test(rest)) {
            continue;
        }
        const indent = getIndentation(state, line.from);
        if (indent === null) {
            continue;
        }
        const wanted = indentString(state, indent);
        if (wanted !== current) {
            changes.push({
                from: line.from,
                to: line.from + current.length,
                insert: wanted,
            });
        }
    }

    return ChangeSet.of(changes, doc.length);
}

/** Leerzeichen und Tabs am Zeilenende entfernen, am Ende genau ein Zeilenumbruch. */
export function tidyChanges(doc: Text): ChangeSet {
    const changes: { from: number; to: number; insert?: string }[] = [];
    for (let n = 1; n <= doc.lines; n++) {
        const line = doc.line(n);
        const trailing = /[ \t]+$/.exec(line.text);
        if (trailing) {
            changes.push({ from: line.from + trailing.index, to: line.to });
        }
    }
    const text = doc.toString();
    const end = /\n*$/.exec(text);
    const blankAtEnd = end ? end[0].length : 0;
    if (text.length > 0 && blankAtEnd !== 1) {
        const from = text.length - blankAtEnd;
        // Leerzeichen der letzten Zeile entfernt schon die Schleife — die Zeilenumbrüche danach ersetzen
        changes.push({ from, to: text.length, insert: '\n' });
    }

    return ChangeSet.of(mergeAtEnd(changes), doc.length);
}

/** Zwei Änderungen am Dokumentende (Leerzeichen der letzten Zeile, Zeilenumbrüche) dürfen sich nicht überlappen. */
function mergeAtEnd(
    changes: { from: number; to: number; insert?: string }[],
): { from: number; to: number; insert?: string }[] {
    const sorted = [...changes].sort((a, b) => a.from - b.from);
    const merged: { from: number; to: number; insert?: string }[] = [];
    for (const change of sorted) {
        const last = merged.at(-1);
        if (last && change.from <= last.to) {
            last.to = Math.max(last.to, change.to);
            last.insert = (last.insert ?? '') + (change.insert ?? '');
        } else {
            merged.push({ ...change });
        }
    }

    return merged;
}

/** Die Änderungen fürs Formatieren — oder ein Fehler (ungültiges JSON). Ohne Änderung: `changes` ist null. */
export function formatChanges(
    state: EditorState,
    kind: CodeKind,
): FormatResult {
    const doc = state.doc;
    if (kind === 'json') {
        let value: unknown;
        try {
            value = JSON.parse(doc.toString());
        } catch (error) {
            return {
                changes: null,
                error: t('JSON lässt sich nicht formatieren: :reason', {
                    reason: (error as Error).message,
                }),
            };
        }
        const formatted = `${JSON.stringify(value, null, state.facet(indentUnit))}\n`;

        return {
            changes:
                formatted === doc.toString()
                    ? null
                    : { from: 0, to: doc.length, insert: formatted },
            error: '',
        };
    }
    if (!formattable(kind)) {
        return { changes: null, error: '' };
    }
    // Den ganzen Baum bauen, sonst fehlt die Einrückung weiter unten (der Parser arbeitet sonst nur im Sichtbereich)
    ensureSyntaxTree(state, doc.length, 2000);
    const indent = reindents(kind)
        ? reindent(state)
        : ChangeSet.empty(doc.length);
    const indented = state.update({ changes: indent }).state;
    const changes = indent.compose(tidyChanges(indented.doc));

    return { changes: changes.empty ? null : changes, error: '' };
}
