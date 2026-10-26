/**
 * Reine Helfer der Theme-Verwaltung (M-28, Mockup 27): Füllung von Regionen, Reihenfolge, schematische Vorschau,
 * Eingaben je Teil-Einstellung, Zuschnitt einer Bildvariante und Bausteine im Block-Editor. Ohne Vue, damit Vitest
 * sie prüfen kann.
 */
import { t, tc } from '../i18n';
import type { BlockValue, SchemaBlock } from '../types';

export type FillWord =
    | 'content'
    | 'content.first'
    | 'content.rest'
    | 'part'
    | 'nav'
    | 'global'
    | 'breadcrumbs'
    | 'languages';

export type FillItem = { word: FillWord; name: string | null; off: boolean };

export type RegionForm = {
    name: string;
    optional: boolean;
    where: 'before' | 'after' | null;
    target: string | null;
    fill: FillItem[];
};

export type LayoutForm = {
    name: string;
    extends: string | null;
    regions: RegionForm[];
};

export type ResolvedRegion = RegionForm & { inherited: boolean; from: string };

export type TemplateForm = {
    name: string;
    for: string;
    layout: string;
    noindex: boolean;
    regions: { name: string; fill: FillItem[] }[];
};

export type PartForm = {
    name: string;
    kind: string;
    settings: Record<string, string>;
    markup: string | null;
};

export type VariantForm = {
    name: string;
    width: number;
    height: number | null;
    mode: 'scale' | 'fit' | 'exact';
    densities: number[];
    sizes: string | null;
    lazy: boolean;
};

export type Sources = {
    nav: string[];
    global: string[];
    form: string[];
    type: string[];
    template: string[];
    layout: string[];
    part: string[];
    locales: string[];
};

/** Wörter mit Namen («part main_nav») — die übrigen stehen allein. */
export const NAMED: FillWord[] = ['part', 'nav', 'global'];

/** Die Beschriftung eines Füllworts — beim Aufruf übersetzt, die Sprache der Oberfläche kann wechseln. */
export function wordLabel(word: FillWord): string {
    const labels: Record<FillWord, string> = {
        content: t('Blöcke der Seite'),
        'content.first': t('Erster Block'),
        'content.rest': t('Übrige Blöcke'),
        part: t('Lava-Teil'),
        nav: t('Navigation'),
        global: t('Globaler Inhalt'),
        breadcrumbs: t('Pfad (Brotkrumen)'),
        languages: t('Sprachwechsel'),
    };

    return labels[word];
}

/** Namen der Regionen aus dem Mockup in der Sprache der Oberfläche — eigene Regionen heissen, wie sie heissen. */
export function regionLabel(name: string): string {
    const labels: Record<string, string> = {
        header: t('Kopf'),
        main: t('Haupt'),
        aside: t('Seitenleiste'),
        footer: t('Fuss'),
        hero: t('Bühne'),
        notice: t('Hinweisband'),
        cta: t('Aufruf'),
    };

    return labels[name] ?? name;
}

/** Eine Zeile einer Region als Text: «part main_nav», «content», «part newsletter off». */
export function fillText(item: FillItem): string {
    return [item.word, item.name, item.off ? 'off' : null]
        .filter(Boolean)
        .join(' ');
}

/** Der Lava-Wert einer Füllung wie im Theme: «part main_nav, part lang_switch». */
export function fillLava(fill: FillItem[]): string {
    return fill.map(fillText).join(', ');
}

/** Ein Element einer Liste verschieben; ausserhalb der Liste bleibt alles, wie es ist. */
export function moveItem<T>(list: T[], index: number, delta: number): T[] {
    const target = index + delta;
    if (
        index < 0 ||
        index >= list.length ||
        target < 0 ||
        target >= list.length
    ) {
        return list;
    }
    const next = [...list];
    const [item] = next.splice(index, 1);
    next.splice(target, 0, item as T);

    return next;
}

/** Regionen in der Reihenfolge des Markups: «before main» / «after header» setzt sie neben ihr Ziel. */
export function placedRegions<
    T extends { name: string; where: string | null; target: string | null },
>(regions: T[]): T[] {
    const ordered = regions.filter((r) => !r.where);
    regions
        .filter((r) => r.where)
        .forEach((r) => {
            const index = ordered.findIndex((o) => o.name === r.target);
            if (index < 0) {
                ordered.push(r);
            } else {
                ordered.splice(r.where === 'after' ? index + 1 : index, 0, r);
            }
        });

    return ordered;
}

/**
 * Die schematische Vorschau (Mockup «Vorschau · standard»): was vor «main» steht, kommt oben über die volle
 * Breite, «main» und «aside» nebeneinander, der Rest unten.
 */
export function scheme<
    T extends { name: string; where: string | null; target: string | null },
>(regions: T[]): { top: T[]; main: T | null; aside: T | null; bottom: T[] } {
    const ordered = placedRegions(regions);
    const main = ordered.find((r) => r.name === 'main') ?? null;
    const aside = ordered.find((r) => r.name === 'aside') ?? null;
    const mainIndex = main ? ordered.indexOf(main) : ordered.length;

    return {
        top: ordered.filter(
            (r, i) => r !== main && r !== aside && i < mainIndex,
        ),
        main,
        aside,
        bottom: ordered.filter(
            (r, i) => r !== main && r !== aside && i > mainIndex,
        ),
    };
}

/** Was eine Region im Schema zeigt: «header · main_nav · lang_switch» — ausgeschaltete Teile fehlen. */
export function schemeText(region: { name: string; fill: FillItem[] }): string {
    return [
        region.name,
        ...region.fill.filter((f) => !f.off).map((f) => f.name ?? f.word),
    ].join(' · ');
}

export type SettingInput =
    | { type: 'select'; options: string[] }
    | { type: 'number' }
    | { type: 'list'; options: string[] }
    | { type: 'text' };

/** Welche Eingabe eine Einstellung bekommt: Quelle als Auswahl aus dem Theme, Tiefe als Zahl, Templates als Liste. */
export function settingInput(
    kind: string,
    setting: string,
    sources: Sources,
): SettingInput {
    if (setting === 'source') {
        const from: Record<string, keyof Sources> = {
            nav: 'nav',
            sitemap: 'nav',
            global: 'global',
            form: 'form',
            list: 'type',
        };
        const key = from[kind];

        return key
            ? { type: 'select', options: sources[key] as string[] }
            : { type: 'text' };
    }
    if (['depth', 'start', 'per'].includes(setting)) {
        return { type: 'number' };
    }
    if (['only', 'exclude'].includes(setting)) {
        return { type: 'list', options: sources.template };
    }

    return { type: 'text' };
}

/** Die Beschriftung einer Teil-Einstellung; unbekannte Einstellungen heissen, wie sie heissen. */
export function settingLabel(setting: string): string {
    const labels: Record<string, string> = {
        source: t('Quelle'),
        depth: t('Tiefe'),
        start: t('Startebene'),
        only: t('Nur Templates'),
        exclude: t('Ohne Templates'),
        label: t('Beschriftung (ARIA-Label)'),
        per: t('Anzahl'),
        moderation: t('Moderation'),
        order: t('Reihenfolge'),
        replies: t('Antworten erlauben'),
        notify: t('Benachrichtigung'),
        closed: t('Geschlossen'),
    };

    return labels[setting] ?? setting;
}

/** Die Kurzbeschreibung einer Instanz in der Region: «Tiefe 2 · Hauptnavigation». */
export function partSummary(form: PartForm): string {
    const s = form.settings;
    const bits: string[] = [];
    if (s.source) {
        bits.push(s.source);
    }
    if (s.depth) {
        bits.push(t('Tiefe :depth', { depth: s.depth }));
    }
    if (s.start) {
        bits.push(t('ab Ebene :start', { start: s.start }));
    }
    if (s.label) {
        bits.push(s.label);
    }

    return bits.join(' · ');
}

/** Gültiger Name für Layout oder Teil — wie in Lava (Kleinbuchstaben, Ziffern, Unterstrich). */
export function isValidName(name: string): boolean {
    return /^[a-z][a-z0-9_]*$/.test(name);
}

export function isValidRegionName(name: string): boolean {
    return /^[a-z][a-z0-9_-]*$/.test(name);
}

/**
 * Der Ausschnitt einer Bildvariante im Original (Anteile 0…1): «exact» schneidet auf das Seitenverhältnis und folgt dem
 * Fokuspunkt, «fit» und «scale» behalten das ganze Bild.
 */
export function cropRect(
    source: { width: number; height: number },
    variant: Pick<VariantForm, 'width' | 'height' | 'mode'>,
    focus: { x: number; y: number },
): { x: number; y: number; w: number; h: number } {
    if (
        variant.mode !== 'exact' ||
        !variant.height ||
        source.width <= 0 ||
        source.height <= 0
    ) {
        return { x: 0, y: 0, w: 1, h: 1 };
    }
    const target = variant.width / variant.height;
    const ratio = source.width / source.height;
    const w = ratio > target ? target / ratio : 1;
    const h = ratio > target ? 1 : ratio / target;
    const clamp = (v: number, size: number): number =>
        Math.min(Math.max(v - size / 2, 0), 1 - size);

    return { x: clamp(focus.x, w), y: clamp(focus.y, h), w, h };
}

/** Die Beschriftung einer Zuschnitt-Art. */
export function modeLabel(mode: VariantForm['mode']): string {
    const labels: Record<VariantForm['mode'], string> = {
        scale: t('proportional'),
        fit: t('Rahmen'),
        exact: t('exakt'),
    };

    return labels[mode];
}

/** «1920 × 1080 · exakt · 1×, 2×» */
export function describeVariant(v: VariantForm): string {
    const size = v.height
        ? `${v.width} × ${v.height}`
        : t(':width breit', { width: v.width });

    return `${size} · ${modeLabel(v.mode)} · ${v.densities.map((d) => `${d}×`).join(', ')}`;
}

/* ── Bausteine im Block-Editor ── */

export const SNIPPET = '@snippet';
export const PART = '@part';

export type SnippetOption = {
    handle: string;
    name: string;
    blocks: BlockValue[];
};

export function isReference(block: BlockValue): boolean {
    return block.type === SNIPPET || block.type === PART;
}

/** «Abschnitt · Karten · Aufruf» — die Blöcke eines Bausteins in Kurzform. */
export function snippetSummary(
    blocks: BlockValue[],
    schema: SchemaBlock[],
): string {
    if (blocks.length === 0) {
        return t('leer');
    }

    return blocks
        .map(
            (b) =>
                schema.find((s) => s.name === b.type)?.label ??
                (b.type === PART
                    ? t('Teil :name', {
                          name:
                              typeof b.data.part === 'string'
                                  ? b.data.part
                                  : '',
                      })
                    : b.type),
        )
        .join(' · ');
}

/** Ein Verweis auf einen Baustein (oder einen Lava-Teil) als neuer Block. */
export function referenceBlock(
    kind: 'snippet' | 'part',
    name: string,
): BlockValue {
    return kind === 'snippet'
        ? { type: SNIPPET, data: { snippet: name } }
        : { type: PART, data: { part: name } };
}

/** Auflösen im Editor: der Verweis wird zu Kopien der Blöcke des Bausteins — die Seite hängt danach nicht mehr daran. */
export function dissolveAt(
    blocks: BlockValue[],
    index: number,
    snippet: SnippetOption,
): BlockValue[] {
    const block = blocks[index];
    if (!block || block.type !== SNIPPET) {
        return blocks;
    }
    const copies = snippet.blocks
        .filter((b) => b.type !== SNIPPET)
        .map((b) => JSON.parse(JSON.stringify(b)) as BlockValue);

    return [...blocks.slice(0, index), ...copies, ...blocks.slice(index + 1)];
}

/** Kennung aus dem Namen: «Leistungs-Trio» → «leistungs-trio». */
export function handleFrom(name: string): string {
    return name
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 64);
}

/**
 * Markup-Zäune im Code einklappen: Im Layout-Editor zählt die Struktur (Regionen, Vererbung), das Markup bleibt Code
 * im Lava-Editor — «markup ``` … 38 Zeilen … ```».
 */
export function collapseMarkup(code: string): {
    code: string;
    collapsed: boolean;
} {
    let collapsed = false;
    const out = code.replace(
        /^(\s*)markup( [a-z_]+)? ```\n([\s\S]*?)\n\s*```$/gm,
        (_m, pad: string, name: string | undefined, body: string) => {
            collapsed = true;
            const lines = body.split('\n').length;

            return `${pad}markup${name ?? ''} \`\`\` … ${tc(':count Zeile Markup (Lava-Editor)|:count Zeilen Markup (Lava-Editor)', lines)} … \`\`\``;
        },
    );

    return { code: out, collapsed };
}
