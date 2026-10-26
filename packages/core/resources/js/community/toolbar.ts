import { icon } from './icons';

/**
 * Werkzeugleiste der Editor-Insel (TB 49): Knöpfe, Menüs und geteilte Knöpfe (Hauptaktion plus Pfeil für die
 * Einstellungen, etwa Codeblock mit Sprache) nach dem Muster «toolbar» von WAI-ARIA — ein Tab-Halt, Pfeiltasten
 * links/rechts, Pos1/Ende; Menüs öffnen mit Klick oder Pfeil nach unten, schliessen mit Escape und geben den Fokus
 * zurück. Knöpfe nehmen dem Editor den Fokus nicht (sonst ginge die Auswahl verloren).
 */
export interface ToolButton {
    kind?: 'button';
    key: string;
    icon: string;
    label: string;
    /** Tastenkürzel für den Hinweis, etwa «⌘B». */
    shortcut?: string;
    run: () => void;
    active?: () => boolean;
    enabled?: () => boolean;
}

export interface ToolMenu {
    kind: 'menu';
    key: string;
    icon: string;
    label: string;
    /** Mit Hauptaktion wird es ein geteilter Knopf; ohne öffnet der Knopf das Menü. */
    run?: () => void;
    active?: () => boolean;
    enabled?: () => boolean;
    /** Beschriftung des Pfeils bei geteilten Knöpfen, etwa «Einstellungen des Codeblocks». */
    menuLabel?: string;
    /** Inhalt des Menüs — bei jedem Öffnen neu, damit er den Stand des Editors zeigt. */
    panel: (close: (refocus?: boolean) => void) => HTMLElement;
}

export type ToolItem = ToolButton | ToolMenu | { kind: 'separator' };

export interface Toolbar {
    refresh(): void;
    closeAll(): void;
}

function button(
    className: string,
    label: string,
    html: string,
): HTMLButtonElement {
    const node = document.createElement('button');
    node.type = 'button';
    node.className = className;
    node.setAttribute('aria-label', label);
    node.title = label;
    node.innerHTML = html;
    // Der Klick darf dem Editor den Fokus nicht nehmen
    node.addEventListener('mousedown', (event) => event.preventDefault());

    return node;
}

/**
 * Ein Menü aus Knöpfen (Listen, Ausrichtung …): Pfeiltasten bewegen den Fokus, aktive Einträge tragen `aria-checked`.
 */
export function menuOf(
    items: ToolButton[],
    close: (refocus?: boolean) => void,
    options: { label: string; horizontal?: boolean; radio?: boolean },
): HTMLElement {
    const menu = document.createElement('div');
    menu.className = `insel-menue__liste${options.horizontal ? ' insel-menue__liste--quer' : ''}`;
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', options.label);
    menu.setAttribute(
        'aria-orientation',
        options.horizontal ? 'horizontal' : 'vertical',
    );
    const entries = items.map((item) => {
        const entry = button(
            'insel-menue__eintrag',
            item.label,
            `${icon(item.icon)}<span class="insel-menue__text">${escapeHtml(item.label)}</span>`,
        );
        entry.setAttribute(
            'role',
            item.active
                ? options.radio
                    ? 'menuitemradio'
                    : 'menuitemcheckbox'
                : 'menuitem',
        );
        if (item.active) {
            entry.setAttribute(
                'aria-checked',
                item.active() ? 'true' : 'false',
            );
        }
        if (item.enabled && !item.enabled()) {
            entry.disabled = true;
        }
        if (item.shortcut) {
            entry.title = `${item.label} (${item.shortcut})`;
        }
        entry.tabIndex = -1;
        entry.addEventListener('click', () => {
            // Die Aktion setzt den Fokus in den Text (weiterschreiben) — Escape dagegen führt zum Knopf zurück
            close(false);
            item.run();
        });
        menu.append(entry);

        return entry;
    });
    menu.addEventListener('keydown', (event) => {
        const enabled = entries.filter((entry) => !entry.disabled);
        const index = enabled.indexOf(
            document.activeElement as HTMLButtonElement,
        );
        const next = options.horizontal ? 'ArrowRight' : 'ArrowDown';
        const previous = options.horizontal ? 'ArrowLeft' : 'ArrowUp';
        let target: HTMLButtonElement | undefined;
        if (event.key === next) {
            target = enabled[(index + 1) % enabled.length];
        } else if (event.key === previous) {
            target = enabled[(index - 1 + enabled.length) % enabled.length];
        } else if (event.key === 'Home') {
            target = enabled[0];
        } else if (event.key === 'End') {
            target = enabled[enabled.length - 1];
        }
        if (target) {
            event.preventDefault();
            target.focus();
        }
    });
    queueMicrotask(() => entries.find((entry) => !entry.disabled)?.focus());

    return menu;
}

export function escapeHtml(text: string): string {
    return text.replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c] ?? c,
    );
}

export function buildToolbar(bar: HTMLElement, items: ToolItem[]): Toolbar {
    const controls: HTMLButtonElement[] = [];
    const updates: (() => void)[] = [];
    let open: {
        group: HTMLElement;
        trigger: HTMLButtonElement;
        panel: HTMLElement;
    } | null = null;

    const closeAll = (refocus = false): void => {
        if (!open) {
            return;
        }
        const { trigger, panel } = open;
        panel.remove();
        trigger.setAttribute('aria-expanded', 'false');
        open = null;
        if (refocus) {
            trigger.focus();
        }
    };

    const openMenu = (
        item: ToolMenu,
        group: HTMLElement,
        trigger: HTMLButtonElement,
    ): void => {
        if (open?.trigger === trigger) {
            closeAll(true);

            return;
        }
        closeAll();
        const panel = document.createElement('div');
        panel.className = 'insel-menue';
        panel.append(item.panel((refocus = true) => closeAll(refocus)));
        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeAll(true);
            } else if (
                event.key === 'Tab' &&
                !panel.querySelector('input, select, textarea')
            ) {
                closeAll();
            }
        });
        bar.append(panel);
        trigger.setAttribute('aria-expanded', 'true');
        open = { group, trigger, panel };
        // Am Knopf ausrichten, aber nie über den Rand der Leiste hinaus
        const left = Math.min(
            group.offsetLeft,
            Math.max(0, bar.clientWidth - panel.offsetWidth - 4),
        );
        panel.style.left = `${Math.max(4, left)}px`;
        panel.style.top = `${group.offsetTop + group.offsetHeight + 4}px`;
    };

    for (const item of items) {
        if (item.kind === 'separator') {
            const separator = document.createElement('span');
            separator.className = 'insel-editor__trenner';
            separator.setAttribute('role', 'separator');
            separator.setAttribute('aria-orientation', 'vertical');
            bar.append(separator);
            continue;
        }
        if (item.kind === 'menu') {
            const group = document.createElement('span');
            group.className = `insel-editor__gruppe${item.run ? ' insel-editor__gruppe--geteilt' : ''}`;
            let main: HTMLButtonElement | null = null;
            if (item.run) {
                main = button(
                    'insel-editor__knopf',
                    item.label,
                    icon(item.icon),
                );
                const run = item.run;
                main.addEventListener('click', () => {
                    closeAll();
                    run();
                });
                if (item.active) {
                    main.setAttribute('aria-pressed', 'false');
                }
                group.append(main);
                controls.push(main);
            }
            const trigger = button(
                item.run
                    ? 'insel-editor__knopf insel-editor__pfeil'
                    : 'insel-editor__knopf insel-editor__knopf--menue',
                item.run ? (item.menuLabel ?? item.label) : item.label,
                item.run
                    ? icon('chevron', 14)
                    : `${icon(item.icon)}${icon('chevron', 14)}`,
            );
            trigger.setAttribute('aria-haspopup', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.addEventListener('click', () =>
                openMenu(item, group, trigger),
            );
            trigger.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (open?.trigger !== trigger) {
                        openMenu(item, group, trigger);
                    }
                }
            });
            group.append(trigger);
            controls.push(trigger);
            bar.append(group);
            updates.push(() => {
                const enabled = item.enabled ? item.enabled() : true;
                trigger.disabled = !enabled;
                if (main) {
                    main.disabled = !enabled;
                    if (item.active) {
                        main.setAttribute(
                            'aria-pressed',
                            item.active() ? 'true' : 'false',
                        );
                    }
                } else if (item.active) {
                    trigger.classList.toggle('ist-aktiv', item.active());
                }
            });
            continue;
        }
        const control = button(
            'insel-editor__knopf',
            item.label,
            icon(item.icon),
        );
        if (item.shortcut) {
            control.title = `${item.label} (${item.shortcut})`;
        }
        if (item.active) {
            control.setAttribute('aria-pressed', 'false');
        }
        control.addEventListener('click', () => {
            closeAll();
            item.run();
        });
        bar.append(control);
        controls.push(control);
        updates.push(() => {
            if (item.active) {
                control.setAttribute(
                    'aria-pressed',
                    item.active() ? 'true' : 'false',
                );
            }
            if (item.enabled) {
                control.disabled = !item.enabled();
            }
        });
    }

    // Ein Tab-Halt für die ganze Leiste, Pfeiltasten dazwischen
    let current = 0;
    const focusable = (): HTMLButtonElement[] =>
        controls.filter((control) => !control.disabled);
    const roving = (): void => {
        const list = focusable();
        if (list.length === 0) {
            return;
        }
        current = Math.min(current, list.length - 1);
        controls.forEach((control) => (control.tabIndex = -1));
        list[current].tabIndex = 0;
    };
    bar.addEventListener('focusin', (event) => {
        const index = focusable().indexOf(event.target as HTMLButtonElement);
        if (index >= 0) {
            current = index;
            roving();
        }
    });
    bar.addEventListener('keydown', (event) => {
        if (open && open.panel.contains(event.target as Node)) {
            return;
        }
        const list = focusable();
        const index = list.indexOf(document.activeElement as HTMLButtonElement);
        if (index < 0) {
            return;
        }
        const moves: Record<string, number> = {
            ArrowRight: index + 1,
            ArrowLeft: index - 1,
            Home: 0,
            End: list.length - 1,
        };
        if (event.key in moves) {
            event.preventDefault();
            current = (moves[event.key] + list.length) % list.length;
            roving();
            list[current].focus();
        }
    });
    document.addEventListener('mousedown', (event) => {
        if (
            open &&
            !open.group.contains(event.target as Node) &&
            !open.panel.contains(event.target as Node)
        ) {
            closeAll();
        }
    });

    const refresh = (): void => {
        updates.forEach((update) => update());
        roving();
    };
    refresh();

    return { refresh, closeAll: () => closeAll() };
}
