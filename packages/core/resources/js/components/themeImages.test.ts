import { describe, expect, it } from 'vitest';
import { groupThemeImages } from './themeImages';

const image = (path: string) => ({ path, url: `/_theme/1/x/assets/${path}` });

describe('groupThemeImages', () => {
    it('ordnet nach Ordner und fasst Fassungen eines Motivs zusammen', () => {
        const folders = groupThemeImages(
            [
                'landing/buehne-foto.webp',
                'landing/buehne-schema.svg',
                'landing/buehne-foto-960.webp',
                'landing/theme.svg',
                'flags/de.svg',
                'logo.svg',
            ].map(image),
        );

        expect(folders.map((f) => [f.name, f.count])).toEqual([
            ['flags', 1],
            ['landing', 4],
            ['', 1],
        ]);
        const landing = folders[1]!;
        expect(landing.groups[0]!.key).toBe('buehne');
        expect(landing.groups[0]!.tiles.map((t) => t.label)).toEqual([
            'Foto',
            'Foto · 960',
            'Schema',
        ]);
        // Einzelne Dateien behalten ihren Namen und stehen nach den Motiven
        expect(landing.groups[1]!.tiles.map((t) => [t.label, t.ext])).toEqual([
            ['theme', 'svg'],
        ]);
    });
});
