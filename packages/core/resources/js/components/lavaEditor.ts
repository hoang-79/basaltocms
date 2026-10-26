/**
 * Hilfen des Lava-Editors (L-17): Befunde und Vorschläge des Sprachdienstes in die Sprache von CodeMirror übersetzen.
 * Der Dienst zählt Zeilen ab 1 und Zeichen (UTF-16) ab 0 — wie der Browser.
 */
import { t, tc } from '../i18n';

export type Finding = {
    file: string;
    line: number;
    column: number;
    character: number;
    severity: string;
    message: string;
};

export type CompletionItem = {
    label: string;
    kind: string;
    detail: string;
    documentation: string | null;
};

/** «fehler» → error, «warnung» → warning, sonst info. */
export function severityOf(severity: string): 'error' | 'warning' | 'info' {
    return severity === 'fehler'
        ? 'error'
        : severity === 'warnung'
          ? 'warning'
          : 'info';
}

/** Anzahl Fehler und Warnungen, z. B. für «geprüft: 1 Fehler, 2 Warnungen». */
export function countFindings(findings: Finding[]): {
    errors: number;
    warnings: number;
} {
    return {
        errors: findings.filter((f) => severityOf(f.severity) === 'error')
            .length,
        warnings: findings.filter((f) => severityOf(f.severity) === 'warning')
            .length,
    };
}

/** «1 Fehler, 2 Warnungen» oder «fehlerfrei». */
export function findingsText(findings: Finding[]): string {
    const { errors, warnings } = countFindings(findings);
    const parts: string[] = [];
    if (errors > 0) {
        parts.push(tc(':count Fehler|:count Fehler', errors));
    }
    if (warnings > 0) {
        parts.push(tc(':count Warnung|:count Warnungen', warnings));
    }

    return parts.length === 0 ? t('fehlerfrei') : parts.join(', ');
}

/**
 * Bereich eines Befunds im Text: ab der Stelle bis zum Ende des Wortes dort (mindestens ein Zeichen, höchstens
 * bis zum Zeilenende). Zeilen ausserhalb des Textes landen am Ende.
 */
export function rangeOf(
    text: string,
    line: number,
    character: number,
): { from: number; to: number } {
    const lines = text.split('\n');
    const index = Math.min(Math.max(line, 1), lines.length) - 1;
    let offset = 0;
    for (let i = 0; i < index; i++) {
        offset += lines[i].length + 1;
    }
    const lineText = lines[index] ?? '';
    const start = Math.min(Math.max(character, 0), lineText.length);
    const word = /^[\w.-]+/.exec(lineText.slice(start));
    const length = word ? word[0].length : 1;

    return {
        from: offset + start,
        to: Math.min(offset + start + length, offset + lineText.length),
    };
}

/** Markdown des Sprachdienstes als schlichter Text für die Erklärung (fett und Code ohne Zeichen). */
export function plainMarkdown(markdown: string): string {
    return markdown.replace(/\*\*(.+?)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1');
}

/** Art eines Vorschlags für das Symbol in der Liste von CodeMirror. */
export function completionType(kind: string): string {
    const types: Record<string, string> = {
        keyword: 'keyword',
        property: 'property',
        fieldType: 'type',
        value: 'enum',
        function: 'function',
        reference: 'variable',
        tag: 'keyword',
    };

    return types[kind] ?? 'text';
}

/** Ein neuer Dateipfad im Theme: Kleinbuchstaben, Ordner mit «/», Endung .lava, .css, .js, .json, .svg, .txt oder .md. */
export function isValidThemePath(path: string): boolean {
    return /^[\w-][\w.-]*(\/[\w-][\w.-]*)*\.(lava|html|json|css|js|svg|txt|md)$/.test(
        path,
    );
}
