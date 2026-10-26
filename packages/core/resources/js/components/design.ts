import { locale } from '../i18n';

/**
 * Kontrast nach WCAG 2.2 (1.4.3) im Browser — dieselbe Rechnung wie `Basalto\Lava\Theme\Design::contrast()`, damit
 * die Station B schon beim Wählen der Farbe zeigt, ob ein Paar die 4,5:1 erreicht.
 */
export function isHex(value: string): boolean {
    return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value);
}

function channel(value: number): number {
    const c = value / 255;

    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

export function luminance(hex: string): number {
    let h = hex.replace('#', '');
    if (h.length === 3 || h.length === 4) {
        h = h
            .slice(0, 3)
            .split('')
            .map((c) => c + c)
            .join('');
    }
    const [r, g, b] = [0, 2, 4].map((i) =>
        channel(parseInt(h.slice(i, i + 2), 16)),
    );

    return 0.2126 * r! + 0.7152 * g! + 0.0722 * b!;
}

/** Kontrastverhältnis 1 bis 21; null, wenn ein Wert keine Farbe ist. */
export function contrast(a: string, b: string): number | null {
    if (!isHex(a) || !isHex(b)) {
        return null;
    }
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/**
 * «4,52:1» — auf zwei Stellen abgeschnitten, nicht gerundet: 4,499 darf nicht als 4,50 durchgehen. Englisch mit
 * Punkt («4.52:1»), die übrigen Sprachen der Oberfläche mit Komma.
 */
export function formatRatio(ratio: number): string {
    const fixed = (Math.floor(ratio * 100) / 100).toFixed(2);

    return `${locale() === 'en' ? fixed : fixed.replace('.', ',')}:1`;
}
