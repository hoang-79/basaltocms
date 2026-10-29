import { describe, expect, it } from 'vitest';
import { inspectPackage, zipEntries } from './paket';

/** Eine ZIP-Datei mit gespeicherten (nicht gepackten) Einträgen — genug für die Vorschau. */
function storedZip(
    files: Record<string, string | Uint8Array>,
    method = 0,
): Uint8Array {
    const encoder = new TextEncoder();
    const locals: number[] = [];
    const central: number[] = [];
    const u16 = (n: number) => [n & 0xff, (n >> 8) & 0xff];
    const u32 = (n: number) => [
        n & 0xff,
        (n >> 8) & 0xff,
        (n >> 16) & 0xff,
        (n >>> 24) & 0xff,
    ];
    let count = 0;
    for (const [name, content] of Object.entries(files)) {
        const nameBytes = [...encoder.encode(name)];
        const data = [
            ...(typeof content === 'string'
                ? encoder.encode(content)
                : content),
        ];
        const offset = locals.length;
        locals.push(
            ...u32(0x04034b50),
            ...u16(20),
            ...u16(0),
            ...u16(method),
            ...u16(0),
            ...u16(0),
            ...u32(0),
            ...u32(data.length),
            ...u32(data.length),
            ...u16(nameBytes.length),
            ...u16(0),
            ...nameBytes,
            ...data,
        );
        central.push(
            ...u32(0x02014b50),
            ...u16(20),
            ...u16(20),
            ...u16(0),
            ...u16(method),
            ...u16(0),
            ...u16(0),
            ...u32(0),
            ...u32(data.length),
            ...u32(data.length),
            ...u16(nameBytes.length),
            ...u16(0),
            ...u16(0),
            ...u16(0),
            ...u16(0),
            ...u32(0),
            ...u32(offset),
            ...nameBytes,
        );
        count++;
    }
    const end = [
        ...u32(0x06054b50),
        ...u16(0),
        ...u16(0),
        ...u16(count),
        ...u16(count),
        ...u32(central.length),
        ...u32(locals.length),
        ...u16(0),
    ];

    return new Uint8Array([...locals, ...central, ...end]);
}

describe('Paketvorschau', () => {
    it('erkennt ein Theme aus manifest.json und theme.json', async () => {
        const zip = storedZip({
            'manifest.json': JSON.stringify({
                format: 1,
                name: 'tavola',
                version: '1.0.0',
            }),
            'tavola/theme.json': JSON.stringify({
                label: 'Tavola',
                description: 'Premium für Restaurants',
            }),
            'tavola/theme.lava': 'lava 1',
        });
        expect(zipEntries(zip)?.map((e) => e.name)).toEqual([
            'manifest.json',
            'tavola/theme.json',
            'tavola/theme.lava',
        ]);
        expect(await inspectPackage(zip)).toEqual({
            ok: true,
            info: {
                kind: 'theme',
                name: 'tavola',
                version: '1.0.0',
                label: 'Tavola',
                description: 'Premium für Restaurants',
            },
        });
    });

    it('erkennt ein Plugin aus composer.json eine Ebene tief', async () => {
        const zip = storedZip({
            'karten/composer.json': JSON.stringify({
                name: 'basalto-plugins/karten',
                version: '1.2.0',
                description: 'Karten',
            }),
        });
        expect(await inspectPackage(zip)).toMatchObject({
            ok: true,
            info: {
                kind: 'plugin',
                name: 'basalto-plugins/karten',
                version: '1.2.0',
            },
        });
    });

    it('entpackt mit Deflate gepackte Einträge', async () => {
        const deflate = async (text: string): Promise<Uint8Array> =>
            new Uint8Array(
                await new Response(
                    new Blob([text])
                        .stream()
                        .pipeThrough(new CompressionStream('deflate-raw')),
                ).arrayBuffer(),
            );
        const zip = storedZip(
            {
                'manifest.json': await deflate(
                    JSON.stringify({ name: 'feder', version: '1.0.0' }),
                ),
            },
            8,
        );
        expect(await inspectPackage(zip)).toMatchObject({
            ok: true,
            info: {
                kind: 'theme',
                name: 'feder',
                version: '1.0.0',
                label: null,
            },
        });
    });

    it('meldet fremde Dateien und Pakete ohne Angaben', async () => {
        expect(
            await inspectPackage(new TextEncoder().encode('kein zip')),
        ).toEqual({ ok: false, reason: 'zip' });
        expect(
            await inspectPackage(storedZip({ 'liesmich.txt': 'Hallo' })),
        ).toEqual({ ok: false, reason: 'inhalt' });
        expect(
            await inspectPackage(storedZip({ 'manifest.json': '{kaputt' })),
        ).toEqual({ ok: false, reason: 'inhalt' });
    });
});
