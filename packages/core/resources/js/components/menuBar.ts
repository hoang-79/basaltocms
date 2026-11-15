import type { MenuItemWithCount } from '../types-workflow';

/**
 * Die Leiste unter der Kopfzeile (TB 120, Variante H): angeheftete Einträge in der Reihenfolge des Menüs, dazu die
 * aktuelle Seite, auch wenn sie nicht angeheftet ist — der Rest steht im Panel «Alle», nach Gruppen.
 */
export type BarItem = MenuItemWithCount & { group?: string; pinned?: boolean };

export type MenuGroup = { key: string; label: string };

/** Zahl an einem Eintrag: wartende Arbeit (`count`) oder ein ruhiger Zähler (`badge`). */
export function countOf(item: BarItem): number {
    return item.count ?? item.badge ?? 0;
}

/** Die Einträge der Leiste: angeheftet in Menüreihenfolge, dahinter die aktuelle Seite, falls sie fehlt. */
export function barItems(
    items: BarItem[],
    pinned: ReadonlySet<string>,
    currentRoute: string | null,
): { item: BarItem; extra: boolean }[] {
    const out = items
        .filter((item) => pinned.has(item.route))
        .map((item) => ({ item, extra: false }));
    const current = items.find((item) => item.route === currentRoute);
    if (current && !pinned.has(current.route)) {
        out.push({ item: current, extra: true });
    }

    return out;
}

/** Die Gruppen des Panels in ihrer Reihenfolge — nur mit Einträgen; unbekannte Gruppen am Schluss. */
export function groupItems(
    items: BarItem[],
    groups: MenuGroup[],
): (MenuGroup & { items: BarItem[] })[] {
    const known = groups.map((group) => ({
        ...group,
        items: items.filter((item) => item.group === group.key),
    }));
    const keys = new Set(groups.map((group) => group.key));
    const rest = items.filter((item) => !keys.has(item.group ?? ''));
    if (rest.length > 0) {
        known.push({ key: 'weitere', label: '', items: rest });
    }

    return known.filter((group) => group.items.length > 0);
}

/** Was hinter «Alle» wartet: Summe der Zahlen nicht angehefteter Einträge, und ob echte Arbeit darunter ist. */
export function hiddenCount(
    items: BarItem[],
    pinned: ReadonlySet<string>,
): { count: number; waiting: boolean } {
    let count = 0;
    let waiting = false;
    for (const item of items) {
        if (pinned.has(item.route)) {
            continue;
        }
        count += countOf(item);
        waiting = waiting || (item.count ?? 0) > 0;
    }

    return { count, waiting };
}

/** Anheften oder lösen — die neue Liste in Menüreihenfolge, so wie sie der Server speichert. */
export function togglePin(
    items: BarItem[],
    pinned: ReadonlySet<string>,
    route: string,
): string[] {
    const next = new Set(pinned);
    if (next.has(route)) {
        next.delete(route);
    } else {
        next.add(route);
    }

    return items.map((item) => item.route).filter((r) => next.has(r));
}
