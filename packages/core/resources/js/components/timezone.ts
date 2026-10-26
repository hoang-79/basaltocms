/**
 * Zeitzonen für Menschen (Profil): statt «Europe/Zurich» in einer Liste mit 400 Einträgen die Stadt, die Uhrzeit
 * dort und der Abstand zu UTC; Suche nach Stadt (auch deutsch: «Wien», «Rom», «Tokio»), Region oder Abstand («+7»).
 * Alles aus `Intl` — ohne Paket, ohne Datendatei.
 */
import { locale, t } from '../i18n';
import { intlLocale } from '../support';

/** Regionen der IANA-Kennung in der Sprache der Oberfläche (Getter: jeder Zugriff liest die aktuelle Sprache). */
export const REGIONS: Record<string, string> = {
    get Europe() {
        return t('Europa');
    },
    get America() {
        return t('Amerika');
    },
    get Asia() {
        return t('Asien');
    },
    get Africa() {
        return t('Afrika');
    },
    get Australia() {
        return t('Australien');
    },
    get Pacific() {
        return t('Pazifik');
    },
    get Atlantic() {
        return t('Atlantik');
    },
    get Indian() {
        return t('Indischer Ozean');
    },
    get Antarctica() {
        return t('Antarktis');
    },
    get Arctic() {
        return t('Arktis');
    },
    get Etc() {
        return t('Weitere');
    },
    get UTC() {
        return t('Weitere');
    },
};

/** Deutsche Namen für Städte, die anders heissen oder oft gesucht werden (nur auf Deutsch; sonst der IANA-Name). */
const GERMAN: Record<string, string> = {
    'Europe/Zurich': 'Zürich',
    'Europe/Vienna': 'Wien',
    'Europe/Rome': 'Rom',
    'Europe/Prague': 'Prag',
    'Europe/Warsaw': 'Warschau',
    'Europe/Moscow': 'Moskau',
    'Europe/Brussels': 'Brüssel',
    'Europe/Copenhagen': 'Kopenhagen',
    'Europe/Lisbon': 'Lissabon',
    'Europe/Athens': 'Athen',
    'Europe/Belgrade': 'Belgrad',
    'Europe/Bucharest': 'Bukarest',
    'Europe/Kyiv': 'Kiew',
    'Europe/Kiev': 'Kiew',
    'Europe/Luxembourg': 'Luxemburg',
    'Europe/Vatican': 'Vatikan',
    'Asia/Ho_Chi_Minh': 'Ho-Chi-Minh-Stadt (Saigon)',
    'Asia/Tokyo': 'Tokio',
    'Asia/Shanghai': 'Shanghai (Peking)',
    'Asia/Singapore': 'Singapur',
    'Asia/Kolkata': 'Kalkutta (Indien)',
    'Asia/Dubai': 'Dubai',
    'America/New_York': 'New York',
    'America/Mexico_City': 'Mexiko-Stadt',
    'America/Sao_Paulo': 'São Paulo',
    'Africa/Cairo': 'Kairo',
    'Atlantic/Canary': 'Kanarische Inseln',
    'Atlantic/Azores': 'Azoren',
};

/** Weitere Suchwörter je Zone (Land, Zweitstadt): «Deutschland» findet Berlin, «Hanoi» findet Saigon. */
const ALIASES: Record<string, string[]> = {
    'Europe/Zurich': ['Schweiz', 'Bern', 'Basel', 'Genf', 'Luzern'],
    'Europe/Berlin': ['Deutschland', 'München', 'Hamburg', 'Frankfurt', 'Köln'],
    'Europe/Vienna': ['Österreich'],
    'Europe/Vaduz': ['Liechtenstein'],
    'Europe/Paris': ['Frankreich'],
    'Europe/Rome': ['Italien', 'Mailand'],
    'Europe/London': ['England', 'Grossbritannien', 'Vereinigtes Königreich'],
    'Europe/Madrid': ['Spanien', 'Barcelona'],
    'Europe/Amsterdam': ['Niederlande', 'Holland'],
    'Asia/Ho_Chi_Minh': ['Vietnam', 'Hanoi', 'Saigon'],
    'Asia/Shanghai': ['China', 'Peking', 'Beijing'],
    'Asia/Kolkata': ['Indien', 'Delhi', 'Mumbai'],
    'America/New_York': ['USA Ostküste', 'Washington', 'Boston'],
    'America/Los_Angeles': ['USA Westküste', 'San Francisco', 'Kalifornien'],
    'America/Chicago': ['USA Mitte', 'Texas'],
};

export type TimezoneOption = {
    id: string;
    city: string;
    region: string;
    /** Minuten Abstand zu UTC jetzt (Sommerzeit berücksichtigt) */
    offset: number;
    offsetLabel: string;
    time: string;
    search: string;
};

/** Abstand zu UTC in Minuten für eine Zone zu einem Zeitpunkt. */
export function offsetMinutes(zone: string, at: Date): number {
    try {
        const part = new Intl.DateTimeFormat('en-US', {
            timeZone: zone,
            timeZoneName: 'longOffset',
        })
            .formatToParts(at)
            .find((p) => p.type === 'timeZoneName')?.value;
        const m = part?.match(/GMT([+-])(\d{2}):?(\d{2})?/);
        if (!m) {
            return 0;
        }
        const minutes = Number(m[2]) * 60 + Number(m[3] ?? 0);

        return m[1] === '-' ? -minutes : minutes;
    } catch {
        return 0;
    }
}

/** «UTC+2», «UTC−3:30», «UTC±0». */
export function formatOffset(minutes: number): string {
    if (minutes === 0) {
        return 'UTC±0';
    }
    const sign = minutes > 0 ? '+' : '−';
    const abs = Math.abs(minutes);
    const h = Math.floor(abs / 60);
    const m = abs % 60;

    return `UTC${sign}${h}${m ? `:${String(m).padStart(2, '0')}` : ''}`;
}

export function cityOf(zone: string): string {
    if (locale() === 'de' && GERMAN[zone]) {
        return GERMAN[zone];
    }
    const parts = zone.split('/');

    return (parts[parts.length - 1] ?? zone).replace(/_/g, ' ');
}

export function regionOf(zone: string): string {
    return REGIONS[zone.split('/')[0] ?? ''] ?? t('Weitere');
}

export function localTime(zone: string, at: Date, hour12 = false): string {
    try {
        return new Intl.DateTimeFormat(intlLocale(), {
            timeZone: zone,
            hour: '2-digit',
            minute: '2-digit',
            hour12,
        }).format(at);
    } catch {
        return '';
    }
}

const fold = (s: string): string =>
    s
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');

export function buildOptions(zones: string[], at: Date): TimezoneOption[] {
    return zones.map((id) => {
        const offset = offsetMinutes(id, at);
        const city = cityOf(id);
        const region = regionOf(id);

        return {
            id,
            city,
            region,
            offset,
            offsetLabel: formatOffset(offset),
            time: localTime(id, at),
            search: fold(
                [
                    id.replace(/[/_]/g, ' '),
                    city,
                    region,
                    ...(ALIASES[id] ?? []),
                ].join(' '),
            ),
        };
    });
}

/**
 * Suchen: Wörter («zur», «wien», «vietnam»), Abstand («+7», «-3», «utc+5:30», «+0»). Ohne Suche alle, sortiert nach
 * Region und Abstand.
 */
export function searchTimezones(
    options: TimezoneOption[],
    query: string,
): TimezoneOption[] {
    const q = fold(query.trim())
        .replace(/^utc\s*/, '')
        .replace(/^gmt\s*/, '');
    if (q === '') {
        return options;
    }
    const offset = q.match(/^([+-−])\s*(\d{1,2})(?::?(\d{2}))?$/);
    if (offset) {
        const minutes =
            (Number(offset[2]) * 60 + Number(offset[3] ?? 0)) *
            (offset[1] === '+' ? 1 : -1);

        return options.filter((o) => o.offset === minutes);
    }
    const words = q.split(/\s+/);

    return options
        .filter((o) => words.every((w) => o.search.includes(w)))
        .sort(
            (a, b) =>
                Number(!fold(a.city).startsWith(words[0] ?? '')) -
                Number(!fold(b.city).startsWith(words[0] ?? '')),
        );
}

/** Gruppen für die Liste: Region → Optionen, Regionen in fester Reihenfolge, darin nach Abstand und Stadt. */
export function groupByRegion(
    options: TimezoneOption[],
): { region: string; items: TimezoneOption[] }[] {
    const order = [
        REGIONS.Europe,
        REGIONS.Africa,
        REGIONS.Asia,
        REGIONS.America,
        REGIONS.Australia,
        REGIONS.Pacific,
        REGIONS.Atlantic,
        REGIONS.Indian,
        REGIONS.Arctic,
        REGIONS.Antarctica,
        REGIONS.Etc,
    ];
    const groups = new Map<string, TimezoneOption[]>();
    for (const o of options) {
        groups.set(o.region, [...(groups.get(o.region) ?? []), o]);
    }

    return order
        .filter((r) => groups.has(r))
        .map((region) => ({
            region,
            items: (groups.get(region) ?? [])
                .slice()
                .sort(
                    (a, b) =>
                        a.offset - b.offset ||
                        a.city.localeCompare(b.city, locale()),
                ),
        }));
}

/** Die Zeitzone dieses Browsers — als Vorschlag oben in der Auswahl. */
export function browserTimezone(): string | null {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? null;
    } catch {
        return null;
    }
}
