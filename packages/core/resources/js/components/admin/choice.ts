/**
 * Auswahllisten in Formularen der Verwaltungsseiten (CR-022) — die Teile ohne Oberfläche: Suchen, Gruppieren und ob
 * «+ … hinzufügen» angeboten wird.
 */
export type AdminChoice = {
    value: string;
    label: string;
    group: string | null;
    fill?: Record<string, string>;
};

export type ChoiceGroup = {
    group: string | null;
    items: { option: AdminChoice; index: number }[];
};

/** Kleinbuchstaben ohne Akzente: «Geschäftsführer» → «geschaftsfuhrer». */
export function fold(text: string): string {
    return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
}

/** Werte, deren Bezeichnung oder Gruppe den Suchtext enthält — in der Reihenfolge der Liste. */
export function filterChoices(
    options: AdminChoice[],
    query: string,
): AdminChoice[] {
    const q = fold(query);
    if (q === '') {
        return [...options];
    }

    return options.filter(
        (o) => fold(o.label).includes(q) || fold(o.group ?? '').includes(q),
    );
}

/**
 * Nach Gruppe ordnen, in der Reihenfolge des ersten Auftretens; Werte ohne Gruppe kommen zuletzt (in Listen mit Gruppen
 * unter «Weitere»). `index` zählt über alle Gruppen — für die Tastatur.
 */
export function groupChoices(options: AdminChoice[]): ChoiceGroup[] {
    const groups: ChoiceGroup[] = [];
    let loose: ChoiceGroup | null = null;
    for (const option of options) {
        if (option.group === null) {
            loose ??= { group: null, items: [] };
            loose.items.push({ option, index: -1 });
            continue;
        }
        let target = groups.find((g) => g.group === option.group);
        if (!target) {
            target = { group: option.group, items: [] };
            groups.push(target);
        }
        target.items.push({ option, index: -1 });
    }
    if (loose) {
        groups.push(loose);
    }
    let index = 0;
    for (const group of groups) {
        for (const item of group.items) {
            item.index = index++;
        }
    }

    return groups;
}

/** «+ … hinzufügen» nur mit Text und nur, wenn es keinen Wert mit genau dieser Bezeichnung gibt. */
export function canAdd(options: AdminChoice[], query: string): boolean {
    const q = fold(query);

    return q !== '' && !options.some((o) => fold(o.label) === q);
}
