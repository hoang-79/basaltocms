/**
 * Telefonnummern je Land (Profil, K-03): lesen, gruppieren, kürzen und Länder suchen — nach den Regeln aus
 * `Countries::all()`, die der Server als Daten mitschickt. Gültig oder nicht entscheidet der Server
 * (`Countries::parsePhone`, gleiche Regeln plus Länge); hier geht es um das Tippen und die Anzeige.
 *
 * Eingaben: Trennzeichen (Leerzeichen, «-», «.», «/», «(», «)») sind erlaubt, sonst nur Ziffern und ein «+» vorne.
 * «+» oder «00» vorne heisst international — die Ziffern danach wählen das Land über die längste passende Vorwahl.
 * Ohne beides gilt das gewählte Land; die Verkehrsausscheidungsziffer 0 fällt weg, ausser wo sie zur Nummer gehört
 * (`keep_zero`, etwa Italien). Auch nach der Vorwahl fällt eine 0 weg: «+41 (0)79 …» ist «+41 79 …».
 */
export type PhoneCountry = {
    code: string;
    name: string;
    dial: string;
    min: number;
    max: number;
    groups: number[];
    keep_zero: boolean;
};

export type ParsedPhone = { country: PhoneCountry; national: string };

/** Was in einer Nummer stehen darf, ohne etwas zu bedeuten. */
const SEPARATORS = /[\s\-./()]/g;

/** Eine gelesene Eingabe — `lead` zählt die Ziffern vor der nationalen Nummer («00», Vorwahl, weggefallene 0). */
type Reading = ParsedPhone & { international: boolean; lead: number };

/** Ohne Trennzeichen; null, wenn etwas anderes als Ziffern und ein «+» vorne darin steht. */
function compact(input: string): string | null {
    const text = input.replace(SEPARATORS, '');

    return /^\+?[0-9]*$/.test(text) ? text : null;
}

/** Steht «+» oder «00» vorne (nach dem Entfernen der Trennzeichen)? Dann die Länge dieses Präfixes, sonst 0. */
function internationalPrefix(text: string): number {
    if (text.startsWith('+')) {
        return 1;
    }

    return text.startsWith('00') ? 2 : 0;
}

/**
 * Das Land zu den Ziffern nach «+»: die längste passende Vorwahl; teilen sich Länder eine Vorwahl, gewinnt das
 * gewählte Land, sonst das erste der Liste.
 */
function byDial(
    digits: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): PhoneCountry | undefined {
    let best: PhoneCountry | undefined;
    for (const c of countries) {
        if (
            c.dial !== '' &&
            digits.startsWith(c.dial) &&
            (!best || c.dial.length > best.dial.length)
        ) {
            best = c;
        }
    }

    return best && chosen && chosen.dial === best.dial ? chosen : best;
}

function read(
    input: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): Reading | null {
    const text = compact(input);
    if (text === null) {
        return null;
    }
    const prefix = internationalPrefix(text);
    let country = chosen;
    let digits = text.slice(prefix);
    let lead = prefix === 2 ? 2 : 0;
    if (prefix > 0) {
        country = byDial(digits, chosen, countries);
        if (!country) {
            return null;
        }
        digits = digits.slice(country.dial.length);
        lead += country.dial.length;
    } else if (!country || digits === '') {
        return null;
    }
    if (!country.keep_zero && digits.startsWith('0')) {
        digits = digits.slice(1);
        lead++;
    }

    return { country, national: digits, international: prefix > 0, lead };
}

/** Land und nationale Ziffern einer Eingabe — ohne Prüfung der Länge; null, wenn nichts erkennbar ist. */
export function parsePhone(
    input: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): ParsedPhone | null {
    const reading = read(input, chosen, countries);

    return reading
        ? { country: reading.country, national: reading.national }
        : null;
}

/** «791234567» mit [2, 3, 2, 2] → «79 123 45 67»; was über die Gruppen hinausgeht, hängt an der letzten. */
export function groupDigits(digits: string, groups: number[]): string {
    if (groups.length === 0) {
        return digits;
    }
    const parts: string[] = [];
    let start = 0;
    groups.forEach((size, index) => {
        if (start >= digits.length) {
            return;
        }
        const end = index === groups.length - 1 ? digits.length : start + size;
        parts.push(digits.slice(start, end));
        start = end;
    });

    return parts.join(' ');
}

/**
 * Lesbar mit Landesvorwahl: «079 123 45 67» (CH) → «+41 79 123 45 67». Was nicht erkennbar ist, bleibt, wie es ist —
 * auch eine einzelne getippte «0», damit sie nicht verschwindet.
 */
export function formatPhone(
    input: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): string {
    const reading = read(input, chosen, countries);
    if (!reading || (!reading.international && reading.national === '')) {
        return input;
    }
    const grouped = groupDigits(reading.national, reading.country.groups);

    return grouped === ''
        ? `+${reading.country.dial}`
        : `+${reading.country.dial} ${grouped}`;
}

/** Schneidet nach der letzten Ziffer ab, die das Land zulässt (`max`); was danach kommt, fällt weg. */
export function limitPhone(
    input: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): string {
    const reading = read(input, chosen, countries);
    if (!reading || reading.national.length < reading.country.max) {
        return input;
    }
    const keep = reading.lead + reading.country.max;
    let seen = 0;
    for (let i = 0; i < input.length; i++) {
        if (input[i] >= '0' && input[i] <= '9') {
            seen++;
            if (seen === keep) {
                return input.slice(0, i + 1);
            }
        }
    }

    return input;
}

/** Wie viele Ziffern bis zur kürzesten gültigen Länge fehlen (0: genug); null, wenn nichts erkennbar ist. */
export function missingDigits(
    input: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): number | null {
    const reading = read(input, chosen, countries);

    return reading
        ? Math.max(0, reading.country.min - reading.national.length)
        : null;
}

/** Die Flagge als Emoji aus zwei Regional-Indicator-Zeichen: «CH» → 🇨🇭; alles andere als zwei Buchstaben → «». */
export function flagOf(code: string): string {
    if (!/^[A-Za-z]{2}$/.test(code)) {
        return '';
    }
    const upper = code.toUpperCase();
    const indicator = (index: number): number =>
        0x1f1e6 + upper.charCodeAt(index) - 65;

    return String.fromCodePoint(indicator(0), indicator(1));
}

/** Kleinbuchstaben ohne Akzente: «Österreich» → «osterreich». */
function fold(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

/**
 * Länder suchen nach Name (ohne Akzente, «osterr»), ISO-Code («ch») oder Vorwahl («41», «+41», «0041»);
 * die Reihenfolge der Liste bleibt.
 */
export function filterCountries<
    T extends { code: string; name: string; dial: string },
>(countries: T[], query: string): T[] {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return [...countries];
    }
    const name = fold(q);
    const dial = /^(?:\+|00)?([0-9]+)$/.exec(q.replace(/\s/g, ''))?.[1];

    return countries.filter(
        (c) =>
            fold(c.name).includes(name) ||
            c.code.toLowerCase().startsWith(q) ||
            (dial !== undefined && c.dial.startsWith(dial)),
    );
}

/**
 * Das Feld hinter der festen Vorwahl: «+» oder «00» vorne (getippt oder eingefügt) stellt das Land um, wenn eine
 * Vorwahl passt; dann nur Ziffern, ohne führende 0 (ausser `keep_zero`), höchstens `max`, gruppiert.
 */
export function nationalInput(
    raw: string,
    chosen: PhoneCountry | undefined,
    countries: PhoneCountry[],
): { country: PhoneCountry | undefined; national: string; grouped: string } {
    const head = raw.replace(SEPARATORS, '');
    const prefix = internationalPrefix(head);
    let country = chosen;
    let digits = raw.replace(/[^0-9]/g, '');
    if (prefix > 0) {
        const after = head.slice(prefix).replace(/[^0-9]/g, '');
        const match = byDial(after, chosen, countries);
        country = match ?? chosen;
        digits = match ? after.slice(match.dial.length) : after;
    }
    if (!country) {
        return { country: undefined, national: digits, grouped: digits };
    }
    const national = (
        !country.keep_zero && digits.startsWith('0') ? digits.slice(1) : digits
    ).slice(0, country.max);

    return {
        country,
        national,
        grouped: groupDigits(national, country.groups),
    };
}
