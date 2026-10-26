import { t } from '../i18n';
import type { RoleArea, RoleLanguages } from '../types-admin';

/**
 * Rollen-Editor (CR-005, R-08): was eine Rolle sieht und in welchen Sprachen sie arbeitet — dieselbe Regel wie
 * im Backend (`Access::seesGroup`, `RoleDefinition::locales`), damit die Vorschau nicht lügt.
 */

/** Gruppen, in denen die Rolle mindestens einen Bereich sieht (Stufe > keine). */
export function visibleGroups(
    levels: Record<string, number>,
    areas: RoleArea[],
): string[] {
    const groups: string[] = [];
    for (const area of areas) {
        if ((levels[area.key] ?? 0) > 0 && !groups.includes(area.group)) {
            groups.push(area.group);
        }
    }

    return groups;
}

/** Sprachen, in denen die Rolle Inhalte bearbeiten darf. */
export function editableLocales(
    languages: RoleLanguages,
    locales: { code: string; is_default: boolean }[],
): string[] {
    const codes = locales.map((l) => l.code);
    if (languages.mode === 'except_default') {
        return locales.filter((l) => !l.is_default).map((l) => l.code);
    }
    if (languages.mode === 'only') {
        return codes.filter((c) => languages.codes.includes(c));
    }

    return codes;
}

/** Kurzform für die Liste: «alle», «alle ausser DE» oder «FR, IT». */
export function languagesText(
    languages: RoleLanguages,
    locales: { code: string; is_default: boolean }[],
): string {
    if (languages.mode === 'all') {
        return t('alle');
    }
    if (languages.mode === 'except_default') {
        const source = locales.find((l) => l.is_default)?.code ?? '';

        return t('alle ausser :locale', { locale: source.toUpperCase() });
    }
    const codes = editableLocales(languages, locales);

    return codes.length === 0
        ? t('keine')
        : codes.map((c) => c.toUpperCase()).join(', ');
}

/** Ob sich zwei Stände einer Rolle unterscheiden (Knopf «Speichern»). */
export function roleChanged(
    a: {
        name: string;
        description: string;
        levels: Record<string, number>;
        languages: RoleLanguages;
    },
    b: typeof a,
): boolean {
    const languageKey = (l: RoleLanguages): string =>
        l.mode === 'only' ? `only:${[...l.codes].sort().join(',')}` : l.mode;
    const keys = new Set([...Object.keys(a.levels), ...Object.keys(b.levels)]);

    return (
        a.name.trim() !== b.name.trim() ||
        a.description.trim() !== b.description.trim() ||
        languageKey(a.languages) !== languageKey(b.languages) ||
        [...keys].some((k) => (a.levels[k] ?? 0) !== (b.levels[k] ?? 0))
    );
}
