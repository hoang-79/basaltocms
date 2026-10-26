import type { Directive } from 'vue';

/**
 * Tastatur und Fokus nach WCAG 2.2 (N-02, TB 08): eine Quelle für Dialoge und Reiter.
 *
 * - `vDialog` gibt einem Dialog das Verhalten, das man von ihm erwartet: Der Fokus geht beim Öffnen hinein (erstes
 *   Eingabefeld, sonst erstes Bedienelement, sonst der Dialog selbst), Escape schliesst, Tab bleibt im Dialog (nur
 *   modal), und beim Schliessen kehrt der Fokus dorthin zurück, wo er vorher war — ausser er ist inzwischen bewusst
 *   woanders hingewandert (z. B. zurück in den Editor).
 * - `nextTabKey` rechnet die Pfeiltasten einer Reiterleiste (APG «Tabs»: ← → Pos1 Ende, ringsum).
 */

const FOCUSABLE =
    'a[href], button, input, select, textarea, summary, [tabindex], [contenteditable="true"]';

/** Die per Tab erreichbaren Elemente in einem Bereich, in Dokumentreihenfolge — ohne gesperrte und unsichtbare. */
export function focusableIn(container: HTMLElement): HTMLElement[] {
    return [...container.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
        (el) =>
            !el.hasAttribute('disabled') &&
            el.getAttribute('tabindex') !== '-1' &&
            el.getAttribute('aria-hidden') !== 'true' &&
            !el.closest('[hidden], [inert], [style*="display: none"]') &&
            el.getAttribute('type') !== 'hidden',
    );
}

/**
 * Wohin der Fokus beim Öffnen geht: in den Editor, sonst ins erste Eingabefeld, sonst in die erste Auswahl, sonst
 * aufs erste Bedienelement, sonst auf den Dialog selbst.
 */
export function initialFocus(container: HTMLElement): HTMLElement {
    const focusable = focusableIn(container);
    const first = (selector: string): HTMLElement | undefined =>
        focusable.find((el) => el.matches(selector));

    return (
        first('[contenteditable="true"]') ??
        first(
            'textarea, input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"])',
        ) ??
        first('select') ??
        focusable[0] ??
        container
    );
}

/**
 * Tab im modalen Dialog: vom letzten Element zum ersten und umgekehrt. Steht der Fokus auf dem Dialog selbst
 * (oder ausserhalb), geht Tab zum ersten und Umschalt+Tab zum letzten Element. Gibt `true` zurück, wenn der Fokus
 * gesetzt wurde (das Ereignis ist dann verbraucht).
 */
export function trapTab(event: KeyboardEvent, container: HTMLElement): boolean {
    if (event.key !== 'Tab') {
        return false;
    }
    const focusable = focusableIn(container);
    const active = document.activeElement as HTMLElement | null;
    const index = active ? focusable.indexOf(active) : -1;
    let target: HTMLElement | undefined;
    if (focusable.length === 0) {
        target = container;
    } else if (index === -1) {
        target = event.shiftKey ? focusable.at(-1) : focusable[0];
    } else if (event.shiftKey && index === 0) {
        target = focusable.at(-1);
    } else if (!event.shiftKey && index === focusable.length - 1) {
        target = focusable[0];
    }
    if (!target) {
        return false;
    }
    event.preventDefault();
    target.focus();

    return true;
}

export type DialogOptions =
    | (() => void)
    | {
          /** Schliessen (Escape) */
          close: () => void;
          /** Modal: Tab bleibt im Dialog. Ein Feld unter der Werkzeugleiste ist nicht modal. Vorgabe: ja */
          modal?: boolean;
      };

type DialogState = {
    previous: HTMLElement | null;
    options: DialogOptions;
    onKey: (event: KeyboardEvent) => void;
};

const states = new WeakMap<HTMLElement, DialogState>();

function closeOf(options: DialogOptions): () => void {
    return typeof options === 'function' ? options : options.close;
}

function isModal(options: DialogOptions): boolean {
    return typeof options === 'function' ? true : options.modal !== false;
}

/** `v-dialog="schliessen"` oder `v-dialog="{ close: schliessen, modal: false }"` auf dem Element mit `role="dialog"`. */
export const vDialog: Directive<HTMLElement, DialogOptions> = {
    mounted(el, binding) {
        const state: DialogState = {
            previous: document.activeElement as HTMLElement | null,
            options: binding.value,
            onKey: (event) => {
                if (event.key === 'Escape') {
                    event.stopPropagation();
                    closeOf(state.options)();
                } else if (isModal(state.options)) {
                    trapTab(event, el);
                }
            },
        };
        states.set(el, state);
        if (!el.hasAttribute('tabindex')) {
            el.setAttribute('tabindex', '-1');
        }
        el.addEventListener('keydown', state.onKey);
        const first = initialFocus(el);
        first.focus();
        // Ein Editor (TipTap) baut sein Eingabefeld erst nach dem Einhängen: im nächsten Bild noch einmal schauen
        requestAnimationFrame(() => {
            const better = el.isConnected ? initialFocus(el) : first;
            if (better !== first && document.activeElement === first) {
                better.focus();
            }
        });
    },
    updated(el, binding) {
        const state = states.get(el);
        if (state) {
            state.options = binding.value;
        }
    },
    beforeUnmount(el) {
        const state = states.get(el);
        if (!state) {
            return;
        }
        el.removeEventListener('keydown', state.onKey);
        states.delete(el);
        const active = document.activeElement;
        const focusWasInside =
            active === null || active === document.body || el.contains(active);
        if (focusWasInside && state.previous?.isConnected) {
            state.previous.focus();
        }
    },
};

/**
 * Die Pfeiltasten einer Reiterleiste: ← und → wechseln ringsum, Pos1 und Ende springen an den Rand. Gibt den
 * Schlüssel des neuen Reiters zurück oder `null`, wenn die Taste die Leiste nichts angeht.
 */
export function nextTabKey(
    keys: string[],
    current: string,
    key: string,
): string | null {
    const index = keys.indexOf(current);
    if (keys.length === 0 || index === -1) {
        return null;
    }
    switch (key) {
        case 'ArrowRight':
            return keys[(index + 1) % keys.length] ?? null;
        case 'ArrowLeft':
            return keys[(index - 1 + keys.length) % keys.length] ?? null;
        case 'Home':
            return keys[0] ?? null;
        case 'End':
            return keys.at(-1) ?? null;
        default:
            return null;
    }
}
