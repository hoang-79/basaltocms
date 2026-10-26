/**
 * Postleitzahl beim Tippen (Profil, Anschrift): In Ländern, deren PLZ nur aus Ziffern besteht (CH, DE, AT …), kommen
 * nur Ziffern ins Feld, höchstens so viele wie im Beispiel; feste Trennzeichen des Landes (Portugal «1000-001»,
 * Polen «00-001», Schweden «111 22») setzt das Feld selbst. Länder mit Buchstaben in der PLZ (GB, CA, NL) bleiben frei,
 * nur Grossbuchstaben. Die Regel des Servers (`Countries::validPostalCode`) entscheidet.
 */
export type PostalCountry = {
    postal_example: string | null;
    postal_numeric: boolean;
    postal_max: number;
};

export function formatPostal(
    input: string,
    country: PostalCountry | undefined,
): string {
    if (!country || !country.postal_numeric || !country.postal_example) {
        return input
            .toUpperCase()
            .replace(/[^A-Z0-9 -]/g, '')
            .slice(0, country?.postal_max ?? 10);
    }
    const example = country.postal_example;
    const digits = input
        .replace(/\D/g, '')
        .slice(0, example.replace(/\D/g, '').length);
    let out = '';
    let used = 0;
    for (const ch of example) {
        if (used >= digits.length) {
            break;
        }
        if (/\d/.test(ch)) {
            out += digits[used];
            used++;
        } else {
            out += ch;
        }
    }

    return out;
}
