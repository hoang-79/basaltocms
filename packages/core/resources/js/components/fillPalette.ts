import { t } from '../i18n';
import type { PartInfo } from '../types';
import { regionLabel, wordLabel } from './layoutEditor';
import type { FillItem, FillWord, Sources } from './layoutEditor';

/**
 * TB 33: Was in eine Region kommt — als Palette statt Auswahlliste, mit einem Satz je Eintrag und Vorschlägen je
 * Region («Kopf»: Hauptnavigation, Sprachwechsel; «Fuss»: Fusszeile, Rechtliches). Ohne Vue, Vitest prüft es.
 */
export type FillOption = {
    key: string;
    item: FillItem;
    label: string;
    name: string | null;
    hint: string;
    group: 'suggest' | 'part' | 'nav' | 'global' | 'page';
};

export function fillGroupLabel(
    group: FillOption['group'],
    region = '',
): string {
    return {
        suggest: t('Vorschläge für «:region»', { region: regionLabel(region) }),
        part: t('Lava-Teile'),
        nav: t('Navigationen'),
        global: t('Globale Inhalte'),
        page: t('Aus der Seite'),
    }[group];
}

function kindHint(kind: string): string {
    return (
        {
            nav: t('Ein Menü aus dem Seitenbaum oder von Hand gepflegt.'),
            breadcrumbs: t('Der Pfad zur Seite: Start › Bereich › Seite.'),
            languages: t('Wechselt zur selben Seite in einer anderen Sprache.'),
            global: t('Ein Einzelinhalt, z. B. Fusszeile oder Kontaktangaben.'),
            form: t('Ein Formular der Site.'),
            sitemap: t('Alle Seiten als Liste.'),
            search: t('Das Suchfeld der Site.'),
            list: t('Neueste Inhalte eines Typs, z. B. Beiträge.'),
            html: t('Eigenes Markup, z. B. ein Hinweisband.'),
        }[kind] ?? ''
    );
}

function hintOf(word: FillWord, kind: string | null): string {
    if (word === 'part' && kind && kindHint(kind) !== '') {
        return kindHint(kind);
    }

    return (
        {
            content: t('Alle Blöcke, die die Redaktion im Editor setzt.'),
            'content.first': t('Nur der erste Block — z. B. die Bühne oben.'),
            'content.rest': t('Alle Blöcke ausser dem ersten.'),
            part: t('Ein Lava-Teil mit eigenen Einstellungen.'),
            nav: t('Ein Menü aus Station D.'),
            global: t('Ein Einzelinhalt, z. B. die Fusszeile.'),
            breadcrumbs: t('Der Pfad zur Seite: Start › Bereich › Seite.'),
            languages: t('Wechselt zur selben Seite in einer anderen Sprache.'),
        }[word] ?? ''
    );
}

/** Alles, was in eine Region kann: Lava-Teile, Navigationen, globale Inhalte und Wörter der Seite. */
export function fillOptions(parts: PartInfo[], sources: Sources): FillOption[] {
    const option = (
        word: FillWord,
        name: string | null,
        label: string,
        group: FillOption['group'],
        kind: string | null = null,
    ): FillOption => ({
        key: `${word} ${name ?? ''}`.trim(),
        item: { word, name, off: false },
        label,
        name,
        hint: hintOf(word, kind),
        group,
    });

    return [
        ...sources.part.map((p) => {
            const part = parts.find((x) => x.name === p);

            return option(
                'part',
                p,
                part?.kind_label ?? t('Lava-Teil'),
                'part',
                part?.kind ?? null,
            );
        }),
        ...sources.nav.map((n) => option('nav', n, t('Navigation'), 'nav')),
        ...sources.global.map((g) =>
            option('global', g, t('Globaler Inhalt'), 'global'),
        ),
        ...(
            [
                'content',
                'content.first',
                'content.rest',
                'breadcrumbs',
                'languages',
            ] as FillWord[]
        ).map((w) => option(w, null, wordLabel(w), 'page')),
    ];
}

const HEADER = /^(header|kopf|top|nav)/;
const FOOTER = /^(footer|fuss|bottom)/;
const HERO = /^(hero|buehne|stage|banner)/;
const MAIN = /^(main|haupt|content|inhalt)/;
const ASIDE = /^(aside|sidebar|seitenleiste)/;

function rank(option: FillOption, parts: PartInfo[], region: string): number {
    const kind =
        option.item.word === 'part'
            ? (parts.find((p) => p.name === option.name)?.kind ?? '')
            : option.item.word;
    const name = option.name ?? '';
    if (HEADER.test(region)) {
        if (kind === 'nav' && /main|haupt|primary/.test(name)) return 1;
        if (kind === 'nav') return 3;
        if (kind === 'languages') return 2;
        if (/^members\.status|search/.test(kind)) return 4;
    }
    if (FOOTER.test(region)) {
        if (option.item.word === 'global' && /footer|fuss/.test(name)) return 1;
        if (kind === 'global') return 2;
        if (kind === 'nav' && /footer|fuss|legal|recht/.test(name)) return 2;
        if (kind === 'sitemap') return 4;
    }
    if (HERO.test(region) && option.item.word === 'content.first') return 1;
    if (MAIN.test(region)) {
        if (option.item.word === 'content') return 1;
        if (kind === 'breadcrumbs') return 2;
    }
    if (ASIDE.test(region)) {
        if (kind === 'search') return 1;
        if (kind === 'list') return 2;
        if (kind === 'nav') return 3;
    }

    return 0;
}

/** Vorschläge für eine Region nach ihrem Namen — ohne, was schon drin ist; höchstens vier. */
export function suggestionsFor(
    region: string,
    options: FillOption[],
    parts: PartInfo[],
    present: FillItem[],
): FillOption[] {
    const key = region.toLowerCase();
    const has = new Set(present.map((p) => `${p.word} ${p.name ?? ''}`.trim()));

    return options
        .map((o) => ({ o, r: rank(o, parts, key) }))
        .filter(({ o, r }) => r > 0 && !has.has(o.key))
        .sort((a, b) => a.r - b.r)
        .slice(0, 4)
        .map(({ o }) => ({ ...o, group: 'suggest' as const }));
}

/** Häufige Regionen mit Platz im Markup — für «+ Region». */
export const COMMON_REGIONS: {
    name: string;
    where: 'before' | 'after';
    target: string;
}[] = [
    { name: 'hero', where: 'before', target: 'main' },
    { name: 'aside', where: 'after', target: 'main' },
    { name: 'notice', where: 'before', target: 'header' },
    { name: 'cta', where: 'after', target: 'main' },
];

/** Eine Kennung für einen neuen Lava-Teil: Quelle und Art («main» + «nav» → «main_nav»), frei im Theme. */
export function partNameFor(
    kind: string,
    source: string,
    taken: string[],
): string {
    const base =
        [source, kind.replace(/\./g, '_')]
            .filter(Boolean)
            .join('_')
            .toLowerCase()
            .replace(/[^a-z0-9_]/g, '_')
            .replace(/^[^a-z]+/, '') || 'teil';
    if (!taken.includes(base)) {
        return base;
    }
    let n = 2;
    while (taken.includes(`${base}_${n}`)) {
        n++;
    }

    return `${base}_${n}`;
}

/** Einstellungen eines Teils, die zuerst stehen — der Rest unter «Weitere Möglichkeiten». */
export const PRIMARY_SETTINGS = ['source', 'label', 'per', 'moderation'];

/** Ein Satz je Einstellung eines Teils. */
export function settingHelp(setting: string): string {
    return (
        {
            source: t(
                'Woher der Teil seinen Inhalt nimmt, z. B. welches Menü.',
            ),
            depth: t('Wie viele Ebenen das Menü zeigt; leer = alle.'),
            start: t('Ab welcher Ebene des Seitenbaums; leer = von oben.'),
            only: t(
                'Nur auf Seiten mit diesen Templates; keine Wahl = überall.',
            ),
            exclude: t('Nicht auf Seiten mit diesen Templates.'),
            label: t(
                'Für Screenreader: wie der Bereich heisst, z. B. «Hauptnavigation».',
            ),
            per: t('Wie viele Einträge höchstens.'),
            moderation: t('Ob neue Kommentare erst nach Freigabe erscheinen.'),
            order: t('In welcher Reihenfolge.'),
            replies: t('Ob man auf Kommentare antworten kann.'),
            notify: t('Wer bei neuen Einträgen eine Nachricht bekommt.'),
            closed: t('Keine neuen Einträge mehr.'),
        }[setting] ?? ''
    );
}
