import { t } from '../i18n';
import { domToDoc, plainText } from './richtext';
import type { EditableField } from './state';

/**
 * Felder der Seite bearbeitbar machen: Jedes Element mit `data-basalto-field`, das die Konfiguration kennt, wird
 * zum Textfeld (`contenteditable`, Rolle, Beschriftung). Text und Textfeld nehmen nur Klartext; Fliesstext kennt
 * Fett (Strg/⌘+B), Kursiv (Strg/⌘+I) und Link (Strg/⌘+K) — ohne Werkzeugleiste. Einfügen immer als Klartext.
 */
export function valueOf(el: HTMLElement, kind: EditableField['kind']): unknown {
    return kind === 'richtext'
        ? domToDoc(el)
        : plainText(el, kind === 'textarea');
}

function supportsPlaintextOnly(el: HTMLElement): boolean {
    try {
        el.contentEditable = 'plaintext-only';

        return el.contentEditable === 'plaintext-only';
    } catch {
        return false;
    }
}

export function enableFields(
    doc: Document,
    fields: Record<string, EditableField>,
    onChange: (field: string, value: unknown) => void,
    onLeave: () => void,
): HTMLElement[] {
    const hosts: HTMLElement[] = [];
    doc.querySelectorAll<HTMLElement>('[data-basalto-field]').forEach((el) => {
        const path = el.dataset.basaltoField ?? '';
        const field = fields[path];
        if (!field || el.dataset.basaltoKind !== field.kind) {
            return;
        }
        if (field.kind === 'richtext' || !supportsPlaintextOnly(el)) {
            el.contentEditable = 'true';
        }
        el.setAttribute('role', 'textbox');
        el.setAttribute('aria-label', field.label);
        if (field.kind !== 'text') {
            el.setAttribute('aria-multiline', 'true');
        }
        el.spellcheck = true;
        let last = JSON.stringify(valueOf(el, field.kind));
        const changed = (): void => {
            const value = valueOf(el, field.kind);
            const json = JSON.stringify(value);
            if (json !== last) {
                last = json;
                onChange(path, value);
            }
        };
        el.addEventListener('input', changed);
        el.addEventListener('blur', () => {
            changed();
            onLeave();
        });
        el.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = event.clipboardData?.getData('text/plain') ?? '';
            doc.execCommand(
                'insertText',
                false,
                field.kind === 'text' ? text.replace(/\s+/g, ' ') : text,
            );
        });
        el.addEventListener('keydown', (event) =>
            keydown(event, el, field.kind, doc),
        );
        hosts.push(el);
    });
    // Links in oder um ein Feld führen beim Bearbeiten nicht weg
    doc.addEventListener(
        'click',
        (event) => {
            const target = event.target as Element | null;
            if (
                target?.closest?.('[data-basalto-field][contenteditable]') &&
                target.closest('a')
            ) {
                event.preventDefault();
            }
        },
        true,
    );

    return hosts;
}

function keydown(
    event: KeyboardEvent,
    el: HTMLElement,
    kind: EditableField['kind'],
    doc: Document,
): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        el.blur();

        return;
    }
    if (kind === 'text' && event.key === 'Enter') {
        event.preventDefault();
        el.blur();

        return;
    }
    const mod = event.metaKey || event.ctrlKey;
    if (!mod || event.altKey) {
        return;
    }
    const key = event.key.toLowerCase();
    if (kind !== 'richtext') {
        // Klartext kennt keine Formatierung
        if (key === 'b' || key === 'i' || key === 'u') {
            event.preventDefault();
        }

        return;
    }
    if (key === 'b' || key === 'i') {
        event.preventDefault();
        doc.execCommand(key === 'b' ? 'bold' : 'italic');
    } else if (key === 'k') {
        event.preventDefault();
        const current =
            doc
                .getSelection()
                ?.anchorNode?.parentElement?.closest('a')
                ?.getAttribute('href') ?? '';
        const href = window.prompt(
            t(
                'Link-Adresse (https://…, /pfad oder mailto:) — leer entfernt den Link',
            ),
            current,
        );
        if (href === null) {
            return;
        }
        doc.execCommand(
            href.trim() === '' ? 'unlink' : 'createLink',
            false,
            href.trim(),
        );
        el.dispatchEvent(new Event('input'));
    }
}

/** Ein Feld als ungültig zeigen (oder wieder gültig). */
export function markInvalid(hosts: HTMLElement[], fields: string[]): void {
    for (const el of hosts) {
        const invalid = fields.includes(el.dataset.basaltoField ?? '');
        if (invalid) {
            el.setAttribute('aria-invalid', 'true');
        } else {
            el.removeAttribute('aria-invalid');
        }
    }
}
