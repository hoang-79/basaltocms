import type { ThemeImage } from '../types';

export type ThemeImageTile = { image: ThemeImage; label: string; ext: string };
export type ThemeImageGroup = { key: string; tiles: ThemeImageTile[] };
export type ThemeImageFolder = {
    name: string;
    count: number;
    groups: ThemeImageGroup[];
};

/** Endungen der Fassungen eines Motivs: «-foto», «-schema», «-foto-960» … und reine Breiten wie «-960». */
const VARIANT =
    /^(.*?)-(foto|schema|bild|grafik|photo|art|overlay)(?:-(.+))?$/i;
const WIDTH = /^(.*?)-(\d{2,4})$/;

/**
 * Bilder des Themes ordnen: nach Ordner (alphabetisch, Dateien direkt unter assets/ zuletzt), darin nach Motiv — der
 * Dateiname ohne Fassung. Motive mit mehreren Dateien bilden eine Gruppe mit kurzen Namen («Foto · 960», «Schema»);
 * einzelne Dateien behalten ihren Namen. Gruppen mit mehreren Dateien stehen vor den einzelnen.
 */
export function groupThemeImages(images: ThemeImage[]): ThemeImageFolder[] {
    const folders = new Map<
        string,
        Map<string, { tile: ThemeImageTile; variant: string }[]>
    >();
    for (const image of images) {
        const slash = image.path.lastIndexOf('/');
        const folder = slash === -1 ? '' : image.path.slice(0, slash);
        const file = image.path.slice(slash + 1);
        const dot = file.lastIndexOf('.');
        const base = dot === -1 ? file : file.slice(0, dot);
        const ext = dot === -1 ? '' : file.slice(dot + 1);
        const variant = VARIANT.exec(base);
        const width = variant ? null : WIDTH.exec(base);
        const stem = variant?.[1] ?? width?.[1] ?? base;
        const label = variant
            ? [variant[2], variant[3]].filter(Boolean).join(' · ')
            : width
              ? (width[2] ?? '')
              : base;
        const motifs = folders.get(folder) ?? new Map();
        folders.set(folder, motifs);
        const members = motifs.get(stem) ?? [];
        motifs.set(stem, members);
        members.push({
            tile: { image, label: base, ext },
            variant: label.charAt(0).toUpperCase() + label.slice(1),
        });
    }

    return [...folders.entries()]
        .sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b)))
        .map(([name, motifs]) => {
            const groups = [...motifs.entries()].map(([key, members]) => ({
                key,
                tiles: members
                    .sort((a, b) => a.variant.localeCompare(b.variant))
                    .map((m) =>
                        members.length > 1
                            ? { ...m.tile, label: m.variant }
                            : m.tile,
                    ),
            }));

            return {
                name,
                count: groups.reduce((sum, g) => sum + g.tiles.length, 0),
                groups: [
                    ...groups.filter((g) => g.tiles.length > 1),
                    ...groups.filter((g) => g.tiles.length === 1),
                ],
            };
        });
}
