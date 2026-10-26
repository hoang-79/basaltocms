import { t } from '../i18n';
import type { FormFieldInput, FormLocale } from '../types-forms';
import { newField, textKey, uniqueName } from './forms';

/**
 * TB 31: Felder hinzufügen wie Blöcke — Gruppen, ein Satz je Typ, eine Zeichnung und fertige «häufige Felder», die
 * Beschriftung, Name und Pflicht mitbringen. Reine Regeln; FieldPalette und FieldSketch zeigen sie an.
 */

/** Ein fertiges Feld: Typ, Name und Beschriftung je Sprache (de, en, it, fr), dazu was es sonst braucht. */
export type FieldPreset = {
    key: string;
    type: string;
    name: string;
    labels: Record<'de' | 'en' | 'it' | 'fr', string>;
    required?: boolean;
    min?: number;
    /** nur Inhaltstypen (TB 32): je Sprache verschieden */
    translatable?: boolean;
};

/** Die Palette eines Formulars (TB 31) oder der Felder eines Inhaltstyps (TB 32). */
export type PaletteKind = 'form' | 'type';

/** TB 32: häufige Felder eines Inhaltstyps — Name, Beschriftung je Sprache, Pflicht und übersetzbar wie üblich. */
export const TYPE_PRESETS: FieldPreset[] = [
    {
        key: 'intro',
        type: 'richtext',
        name: 'intro',
        translatable: true,
        labels: {
            de: 'Einleitung',
            en: 'Introduction',
            it: 'Introduzione',
            fr: 'Introduction',
        },
    },
    {
        key: 'content',
        type: 'blocks',
        name: 'content',
        labels: { de: 'Inhalt', en: 'Content', it: 'Contenuto', fr: 'Contenu' },
    },
    {
        key: 'image',
        type: 'image',
        name: 'image',
        labels: { de: 'Bild', en: 'Image', it: 'Immagine', fr: 'Image' },
    },
    {
        key: 'gallery',
        type: 'gallery',
        name: 'gallery',
        labels: { de: 'Galerie', en: 'Gallery', it: 'Galleria', fr: 'Galerie' },
    },
    {
        key: 'date',
        type: 'date',
        name: 'date',
        labels: { de: 'Datum', en: 'Date', it: 'Data', fr: 'Date' },
    },
    {
        key: 'category',
        type: 'select',
        name: 'category',
        labels: {
            de: 'Kategorie',
            en: 'Category',
            it: 'Categoria',
            fr: 'Catégorie',
        },
    },
    {
        key: 'tags',
        type: 'tags',
        name: 'tags',
        labels: {
            de: 'Schlagwörter',
            en: 'Tags',
            it: 'Parole chiave',
            fr: 'Mots-clés',
        },
    },
    {
        key: 'location',
        type: 'text',
        name: 'location',
        translatable: true,
        labels: { de: 'Ort', en: 'Location', it: 'Luogo', fr: 'Lieu' },
    },
    {
        key: 'price',
        type: 'number',
        name: 'price',
        labels: { de: 'Preis', en: 'Price', it: 'Prezzo', fr: 'Prix' },
    },
    {
        key: 'link',
        type: 'link',
        name: 'link',
        labels: { de: 'Link', en: 'Link', it: 'Link', fr: 'Lien' },
    },
    {
        key: 'client',
        type: 'text',
        name: 'client',
        labels: { de: 'Kunde', en: 'Client', it: 'Cliente', fr: 'Client' },
    },
];

const TYPE_GROUP_OF: Record<string, string> = {
    text: 'text',
    textarea: 'text',
    richtext: 'text',
    markdown: 'text',
    email: 'text',
    url: 'text',
    slug: 'text',
    image: 'media',
    gallery: 'media',
    file: 'media',
    select: 'choice',
    checkbox: 'choice',
    bool: 'choice',
    tags: 'choice',
    number: 'choice',
    date: 'choice',
    datetime: 'choice',
    color: 'choice',
    link: 'structure',
    relation: 'structure',
    blocks: 'structure',
    repeater: 'structure',
    code: 'tech',
    json: 'tech',
    seo: 'tech',
};

export const PRESETS: FieldPreset[] = [
    {
        key: 'name',
        type: 'text',
        name: 'name',
        required: true,
        labels: { de: 'Name', en: 'Name', it: 'Nome', fr: 'Nom' },
    },
    {
        key: 'vorname',
        type: 'text',
        name: 'vorname',
        required: true,
        labels: { de: 'Vorname', en: 'First name', it: 'Nome', fr: 'Prénom' },
    },
    {
        key: 'nachname',
        type: 'text',
        name: 'nachname',
        required: true,
        labels: { de: 'Nachname', en: 'Last name', it: 'Cognome', fr: 'Nom' },
    },
    {
        key: 'email',
        type: 'email',
        name: 'email',
        required: true,
        labels: { de: 'E-Mail', en: 'Email', it: 'E-mail', fr: 'E-mail' },
    },
    {
        key: 'telefon',
        type: 'tel',
        name: 'telefon',
        labels: { de: 'Telefon', en: 'Phone', it: 'Telefono', fr: 'Téléphone' },
    },
    {
        key: 'firma',
        type: 'text',
        name: 'firma',
        labels: { de: 'Firma', en: 'Company', it: 'Azienda', fr: 'Entreprise' },
    },
    {
        key: 'nachricht',
        type: 'textarea',
        name: 'nachricht',
        required: true,
        labels: {
            de: 'Nachricht',
            en: 'Message',
            it: 'Messaggio',
            fr: 'Message',
        },
    },
    {
        key: 'termin',
        type: 'date',
        name: 'termin',
        labels: {
            de: 'Wunschtermin',
            en: 'Preferred date',
            it: 'Data desiderata',
            fr: 'Date souhaitée',
        },
    },
    {
        key: 'personen',
        type: 'number',
        name: 'personen',
        min: 1,
        labels: {
            de: 'Anzahl Personen',
            en: 'Number of people',
            it: 'Numero di persone',
            fr: 'Nombre de personnes',
        },
    },
    {
        key: 'anhang',
        type: 'file',
        name: 'anhang',
        labels: {
            de: 'Anhang',
            en: 'Attachment',
            it: 'Allegato',
            fr: 'Pièce jointe',
        },
    },
    {
        key: 'rueckruf',
        type: 'checkbox',
        name: 'rueckruf',
        labels: {
            de: 'Bitte rufen Sie mich zurück',
            en: 'Please call me back',
            it: 'Richiamatemi, per favore',
            fr: 'Merci de me rappeler',
        },
    },
];

const GROUP_OF: Record<string, string> = {
    text: 'input',
    email: 'input',
    tel: 'input',
    number: 'input',
    url: 'input',
    date: 'input',
    textarea: 'input',
    select: 'choice',
    radio: 'choice',
    checkbox: 'choice',
    range: 'choice',
    explanation: 'layout',
    group: 'layout',
    file: 'more',
    hidden: 'more',
};

export function groupLabel(key: string): string {
    return (
        {
            common: t('Häufige Felder'),
            text: t('Text'),
            media: t('Bilder und Dateien'),
            structure: t('Aufbau und Verweise'),
            tech: t('Technik'),
            input: t('Eingabe'),
            choice: t('Auswahl'),
            layout: t('Aufbau'),
            more: t('Dateien und Technik'),
        }[key] ?? t('Weitere')
    );
}

/** TB 32: ein Satz je Feldtyp aus Lava — was die Redaktion im Editor bekommt. */
export function lavaTypeHint(type: string): string {
    return (
        {
            text: t('Eine Zeile, z. B. ein Titel oder Ort.'),
            textarea: t('Mehrere Zeilen ohne Formatierung.'),
            richtext: t('Formatierter Text mit Absätzen, Listen und Links.'),
            markdown: t('Text in Markdown, für Kundige.'),
            email: t('Eine E-Mail-Adresse, geprüft.'),
            url: t('Eine Webadresse, geprüft.'),
            slug: t('Ein Kurzname für Adressen, a–z und Bindestrich.'),
            image: t('Ein Bild aus der Mediathek, mit Alt-Text.'),
            gallery: t('Mehrere Bilder in einer Reihenfolge.'),
            file: t('Eine Datei aus der Mediathek, z. B. ein PDF.'),
            select: t('Eine Wahl aus festen Möglichkeiten.'),
            checkbox: t('Ja oder nein, als Schalter.'),
            bool: t('Ja oder nein, als Schalter.'),
            tags: t('Mehrere Schlagwörter, frei eingetippt.'),
            number: t('Eine Zahl, z. B. ein Preis.'),
            date: t('Ein Datum aus dem Kalender.'),
            datetime: t('Datum und Uhrzeit.'),
            color: t('Eine Farbe aus dem Farbwähler.'),
            link: t('Adresse, Beschriftung und Ziel eines Links.'),
            relation: t(
                'Verweist auf einen anderen Inhalt, z. B. eine Person.',
            ),
            blocks: t('Blöcke wie auf einer Seite — der Inhalt des Typs.'),
            repeater: t('Mehrere gleich gebaute Zeilen, z. B. Karten.'),
            code: t('Quelltext in fester Schrift.'),
            json: t('Strukturierte Daten, geprüft.'),
            seo: t('Titel und Beschreibung für Suchmaschinen.'),
        }[type] ?? ''
    );
}

/** Ein Satz je Typ — was Besucher sehen und was der Typ prüft. */
export function typeHint(type: string): string {
    return (
        {
            text: t('Eine Zeile, z. B. Name oder Ort.'),
            email: t(
                'Prüft die Adresse; Antworten lassen sich direkt dorthin schicken.',
            ),
            tel: t('Telefonnummer — auf dem Handy mit Zifferntastatur.'),
            number: t('Nur Zahlen, mit Minimum und Maximum.'),
            url: t('Eine Webadresse, geprüft.'),
            date: t('Ein Datum aus dem Kalender.'),
            textarea: t('Mehrere Zeilen, z. B. eine Nachricht.'),
            select: t('Eine Wahl aus einer aufklappenden Liste.'),
            radio: t('Eine Wahl, alle Möglichkeiten sichtbar.'),
            checkbox: t('Ein Häkchen, z. B. «Rückruf erwünscht».'),
            range: t('Ein Schieberegler zwischen zwei Zahlen.'),
            file: t('Eine Datei hochladen, mit Typen und Grösse.'),
            hidden: t('Unsichtbar mitgeschickt, z. B. eine Kampagne.'),
            explanation: t('Ein Text ohne Eingabe, z. B. ein Hinweis.'),
            group: t('Fasst Felder unter einer Überschrift zusammen.'),
        }[type] ?? ''
    );
}

export type PaletteItem = {
    key: string;
    type: string;
    label: string;
    hint: string;
    group: string;
    preset: FieldPreset | null;
};

/**
 * Die Karten der Palette: zuerst die häufigen Felder (nur, wo alle Typen erlaubt sind — in Gruppen nicht), dann je
 * Typ eine Karte in ihrer Gruppe. Unbekannte Typen (aus Modulen) stehen unter «Weitere».
 */
export function paletteItems(
    types: { value: string; label: string }[],
    locale: string,
    presets = true,
    kind: PaletteKind = 'form',
): PaletteItem[] {
    const hint = kind === 'type' ? lavaTypeHint : typeHint;
    const groupOf = kind === 'type' ? TYPE_GROUP_OF : GROUP_OF;
    const presetList = kind === 'type' ? TYPE_PRESETS : PRESETS;
    const allowed = new Set(types.map((x) => x.value));
    const lang = (
        ['de', 'en', 'it', 'fr'].includes(locale) ? locale : 'en'
    ) as keyof FieldPreset['labels'];
    const common = presets
        ? presetList
              .filter((p) => allowed.has(p.type))
              .map((p) => ({
                  key: `preset-${p.key}`,
                  type: p.type,
                  label: p.labels[lang],
                  hint: hint(p.type),
                  group: 'common',
                  preset: p,
              }))
        : [];

    return [
        ...common,
        ...types.map((x) => ({
            key: x.value,
            type: x.value,
            label: x.label,
            hint: hint(x.value),
            group: groupOf[x.value] ?? 'other',
            preset: null,
        })),
    ];
}

/** Ein Feld aus einer Vorlage: Beschriftung je Sprache der Site, freier Name, Pflicht und Minimum wie vorgesehen. */
export function presetField(
    preset: FieldPreset,
    typeLabel: string,
    locales: FormLocale[],
    taken: string[],
): FormFieldInput {
    const field = newField(preset.type, typeLabel, taken);
    field.name = uniqueName(preset.name, taken);
    field.required = preset.required === true;
    field.labels = {};
    for (const l of locales) {
        const code = l.code as keyof FieldPreset['labels'];
        const label =
            preset.labels[code] ?? (l.default ? preset.labels.en : '');
        if (label) {
            field.labels[textKey(l)] = label;
        }
    }
    if (preset.min !== undefined) {
        field.min = preset.min;
    }

    return field;
}

/* ── Zeichnung je Typ (160 × 100, wie die Skizzen der Blöcke) ── */

export type SketchShape =
    | {
          el: 'rect';
          x: number;
          y: number;
          w: number;
          h: number;
          r?: number;
          cls: string;
          dash?: boolean;
      }
    | { el: 'circle'; x: number; y: number; r: number; cls: string }
    | { el: 'path'; d: string; cls: string };

const LABEL: SketchShape = {
    el: 'rect',
    x: 14,
    y: 16,
    w: 46,
    h: 6,
    r: 3,
    cls: 'fill-basalt-light',
};
const BOX = (h = 24, w = 132, dash = false): SketchShape => ({
    el: 'rect',
    x: 14,
    y: 30,
    w,
    h,
    r: 4,
    cls: 'fill-white stroke-ash',
    dash,
});
const VALUE = (w = 54): SketchShape => ({
    el: 'rect',
    x: 22,
    y: 39,
    w,
    h: 6,
    r: 3,
    cls: 'fill-ash-light',
});

export function fieldSketch(type: string): SketchShape[] {
    switch (type) {
        case 'email':
            return [
                LABEL,
                BOX(),
                VALUE(36),
                {
                    el: 'circle',
                    x: 66,
                    y: 42,
                    r: 4,
                    cls: 'fill-none stroke-lava',
                },
                {
                    el: 'rect',
                    x: 74,
                    y: 39,
                    w: 30,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                },
            ];
        case 'tel':
            return [
                LABEL,
                BOX(),
                ...[0, 1, 2, 3].map((i): SketchShape => ({
                    el: 'rect',
                    x: 22 + i * 16,
                    y: 39,
                    w: 12,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                })),
            ];
        case 'number':
            return [
                LABEL,
                BOX(24, 72),
                VALUE(20),
                {
                    el: 'path',
                    d: 'M72 38l4-4 4 4M72 46l4 4 4-4',
                    cls: 'fill-none stroke-basalt-light',
                },
            ];
        case 'url':
            return [
                LABEL,
                BOX(),
                {
                    el: 'rect',
                    x: 22,
                    y: 39,
                    w: 28,
                    h: 6,
                    r: 3,
                    cls: 'fill-lava/70',
                },
                {
                    el: 'rect',
                    x: 54,
                    y: 39,
                    w: 40,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                },
            ];
        case 'date':
            return [
                LABEL,
                BOX(),
                VALUE(40),
                {
                    el: 'rect',
                    x: 124,
                    y: 36,
                    w: 14,
                    h: 12,
                    r: 2,
                    cls: 'fill-none stroke-lava',
                },
                { el: 'path', d: 'M124 40h14', cls: 'fill-none stroke-lava' },
            ];
        case 'textarea':
            return [
                LABEL,
                BOX(56),
                VALUE(100),
                {
                    el: 'rect',
                    x: 22,
                    y: 52,
                    w: 90,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                },
                {
                    el: 'rect',
                    x: 22,
                    y: 65,
                    w: 60,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                },
            ];
        case 'select':
            return [
                LABEL,
                BOX(),
                VALUE(50),
                {
                    el: 'path',
                    d: 'M126 39l5 5 5-5',
                    cls: 'fill-none stroke-basalt-light',
                },
            ];
        case 'radio':
            return [
                LABEL,
                ...[0, 1, 2].map((i): SketchShape => ({
                    el: 'circle',
                    x: 20,
                    y: 38 + i * 16,
                    r: 5,
                    cls:
                        i === 0
                            ? 'fill-lava stroke-lava'
                            : 'fill-white stroke-ash',
                })),
                ...[0, 1, 2].map((i): SketchShape => ({
                    el: 'rect',
                    x: 32,
                    y: 35 + i * 16,
                    w: 60 - i * 12,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                })),
            ];
        case 'checkbox':
            return [
                {
                    el: 'rect',
                    x: 14,
                    y: 34,
                    w: 12,
                    h: 12,
                    r: 2,
                    cls: 'fill-lava stroke-lava',
                },
                {
                    el: 'path',
                    d: 'M17 40l3 3 5-6',
                    cls: 'fill-none stroke-white',
                },
                {
                    el: 'rect',
                    x: 34,
                    y: 37,
                    w: 90,
                    h: 6,
                    r: 3,
                    cls: 'fill-basalt-light',
                },
            ];
        case 'range':
            return [
                LABEL,
                {
                    el: 'rect',
                    x: 14,
                    y: 46,
                    w: 132,
                    h: 4,
                    r: 2,
                    cls: 'fill-ash-light',
                },
                {
                    el: 'rect',
                    x: 14,
                    y: 46,
                    w: 70,
                    h: 4,
                    r: 2,
                    cls: 'fill-lava/70',
                },
                {
                    el: 'circle',
                    x: 84,
                    y: 48,
                    r: 7,
                    cls: 'fill-white stroke-lava',
                },
            ];
        case 'file':
            return [
                LABEL,
                {
                    el: 'rect',
                    x: 14,
                    y: 30,
                    w: 132,
                    h: 48,
                    r: 6,
                    cls: 'fill-white stroke-ash',
                    dash: true,
                },
                {
                    el: 'path',
                    d: 'M80 64V44M72 52l8-8 8 8',
                    cls: 'fill-none stroke-lava',
                },
            ];
        case 'hidden':
            return [
                {
                    el: 'rect',
                    x: 14,
                    y: 30,
                    w: 132,
                    h: 24,
                    r: 4,
                    cls: 'fill-none stroke-ash',
                    dash: true,
                },
                {
                    el: 'path',
                    d: 'M64 42c6-6 26-6 32 0c-6 6-26 6-32 0zM62 48l36-12',
                    cls: 'fill-none stroke-ash',
                },
            ];
        case 'explanation':
            return [0, 1, 2].map((i): SketchShape => ({
                el: 'rect',
                x: 14,
                y: 26 + i * 14,
                w: 132 - i * 30,
                h: 6,
                r: 3,
                cls: 'fill-ash',
            }));
        case 'group':
            return [
                {
                    el: 'rect',
                    x: 10,
                    y: 20,
                    w: 140,
                    h: 70,
                    r: 6,
                    cls: 'fill-none stroke-ash',
                },
                {
                    el: 'rect',
                    x: 18,
                    y: 16,
                    w: 44,
                    h: 8,
                    r: 3,
                    cls: 'fill-basalt-light',
                },
                {
                    el: 'rect',
                    x: 20,
                    y: 34,
                    w: 120,
                    h: 18,
                    r: 4,
                    cls: 'fill-white stroke-ash',
                },
                {
                    el: 'rect',
                    x: 20,
                    y: 60,
                    w: 120,
                    h: 18,
                    r: 4,
                    cls: 'fill-white stroke-ash',
                },
            ];
        case 'richtext':
        case 'markdown':
            return [
                LABEL,
                {
                    el: 'rect',
                    x: 14,
                    y: 30,
                    w: 132,
                    h: 14,
                    r: 3,
                    cls: 'fill-ash-light',
                },
                ...[0, 1, 2].map((i): SketchShape => ({
                    el: 'rect',
                    x: 18 + i * 12,
                    y: 34,
                    w: 8,
                    h: 6,
                    r: 1,
                    cls: 'fill-basalt-light',
                })),
                {
                    el: 'rect',
                    x: 14,
                    y: 50,
                    w: 132,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash',
                },
                {
                    el: 'rect',
                    x: 14,
                    y: 62,
                    w: 110,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash',
                },
                {
                    el: 'rect',
                    x: 14,
                    y: 74,
                    w: 70,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash',
                },
            ];
        case 'image':
            return [
                LABEL,
                {
                    el: 'rect',
                    x: 14,
                    y: 30,
                    w: 132,
                    h: 54,
                    r: 4,
                    cls: 'fill-ash-light',
                },
                {
                    el: 'path',
                    d: 'M24 78l26-24 18 16 14-10 28 18',
                    cls: 'fill-none stroke-ash',
                },
                { el: 'circle', x: 120, y: 44, r: 6, cls: 'fill-white' },
            ];
        case 'gallery':
            return [
                LABEL,
                ...[0, 1, 2].map((i): SketchShape => ({
                    el: 'rect',
                    x: 14 + i * 45,
                    y: 32,
                    w: 40,
                    h: 40,
                    r: 4,
                    cls: i === 1 ? 'fill-lava/70' : 'fill-ash-light',
                })),
            ];
        case 'link':
            return [
                LABEL,
                {
                    el: 'rect',
                    x: 14,
                    y: 32,
                    w: 60,
                    h: 20,
                    r: 10,
                    cls: 'fill-lava',
                },
                {
                    el: 'rect',
                    x: 82,
                    y: 39,
                    w: 50,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash',
                },
            ];
        case 'blocks':
            return [0, 1, 2].map((i): SketchShape => ({
                el: 'rect',
                x: 14,
                y: 16 + i * 26,
                w: 132,
                h: 20,
                r: 4,
                cls:
                    i === 0
                        ? 'fill-white stroke-lava'
                        : 'fill-white stroke-ash',
            }));
        case 'repeater':
            return [
                LABEL,
                ...[0, 1, 2].map((i): SketchShape => ({
                    el: 'rect',
                    x: 14 + i * 45,
                    y: 30,
                    w: 40,
                    h: 50,
                    r: 4,
                    cls: 'fill-white stroke-ash',
                })),
            ];
        case 'relation':
            return [
                LABEL,
                BOX(),
                {
                    el: 'rect',
                    x: 20,
                    y: 36,
                    w: 60,
                    h: 12,
                    r: 6,
                    cls: 'fill-lava/70',
                },
                {
                    el: 'path',
                    d: 'M126 39l5 5 5-5',
                    cls: 'fill-none stroke-basalt-light',
                },
            ];
        case 'tags':
            return [
                LABEL,
                BOX(),
                ...[0, 1, 2].map((i): SketchShape => ({
                    el: 'rect',
                    x: 20 + i * 38,
                    y: 36,
                    w: 32,
                    h: 12,
                    r: 6,
                    cls: 'fill-ash',
                })),
            ];
        case 'color':
            return [
                LABEL,
                {
                    el: 'rect',
                    x: 14,
                    y: 30,
                    w: 24,
                    h: 24,
                    r: 4,
                    cls: 'fill-lava',
                },
                {
                    el: 'rect',
                    x: 44,
                    y: 30,
                    w: 80,
                    h: 24,
                    r: 4,
                    cls: 'fill-white stroke-ash',
                },
            ];
        case 'code':
        case 'json':
            return [
                {
                    el: 'rect',
                    x: 14,
                    y: 16,
                    w: 132,
                    h: 66,
                    r: 4,
                    cls: 'fill-basalt',
                },
                ...[0, 1, 2, 3].map((i): SketchShape => ({
                    el: 'rect',
                    x: 22,
                    y: 26 + i * 12,
                    w: 90 - i * 14,
                    h: 5,
                    r: 2,
                    cls: i === 1 ? 'fill-lava/80' : 'fill-ash',
                })),
            ];
        case 'seo':
            return [
                LABEL,
                BOX(18),
                {
                    el: 'rect',
                    x: 14,
                    y: 54,
                    w: 132,
                    h: 28,
                    r: 4,
                    cls: 'fill-white stroke-ash',
                },
            ];
        case 'slug':
            return [
                LABEL,
                BOX(),
                {
                    el: 'rect',
                    x: 22,
                    y: 39,
                    w: 8,
                    h: 6,
                    r: 2,
                    cls: 'fill-lava/70',
                },
                {
                    el: 'rect',
                    x: 34,
                    y: 39,
                    w: 50,
                    h: 6,
                    r: 3,
                    cls: 'fill-ash-light',
                },
            ];
        case 'bool':
            return fieldSketch('checkbox');
        case 'datetime':
            return fieldSketch('date');
        default:
            return [LABEL, BOX(), VALUE()];
    }
}
