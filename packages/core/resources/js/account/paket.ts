/**
 * Vorschau eines Pakets im Browser (CR-016, TB 86): Aus einer ZIP-Datei liest das Einreichen Art, Name und Version,
 * bevor hochgeladen wird — Theme aus `manifest.json` (basalto:theme-pack) und `<name>/theme.json`, Plugin aus
 * `composer.json` (im Wurzelordner oder eine Ebene tiefer). Gelesen wird nur das Zentralverzeichnis und die zwei, drei
 * kleinen Dateien; entpackt mit `DecompressionStream` (ohne Bibliothek). Geprüft wird später auf dem Server.
 */
export type PackageInfo = {
    kind: 'theme' | 'plugin';
    name: string;
    version: string;
    label: string | null;
    description: string | null;
};

export type PackageResult =
    | { ok: true; info: PackageInfo }
    | { ok: false; reason: 'zip' | 'inhalt' };

type Entry = { name: string; method: number; size: number; offset: number };

const EOCD = 0x06054b50;
const CENTRAL = 0x02014b50;
const LOCAL = 0x04034b50;

/** Die Einträge des Zentralverzeichnisses — null, wenn das keine ZIP-Datei ist. */
export function zipEntries(bytes: Uint8Array): Entry[] | null {
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    let end = -1;
    for (
        let i = bytes.length - 22;
        i >= Math.max(0, bytes.length - 65557);
        i--
    ) {
        if (view.getUint32(i, true) === EOCD) {
            end = i;
            break;
        }
    }
    if (end < 0) {
        return null;
    }
    const count = view.getUint16(end + 10, true);
    let at = view.getUint32(end + 16, true);
    const entries: Entry[] = [];
    const decoder = new TextDecoder();
    for (let n = 0; n < count; n++) {
        if (at + 46 > bytes.length || view.getUint32(at, true) !== CENTRAL) {
            return null;
        }
        const method = view.getUint16(at + 10, true);
        const size = view.getUint32(at + 20, true);
        const nameLength = view.getUint16(at + 28, true);
        const extra = view.getUint16(at + 30, true);
        const comment = view.getUint16(at + 32, true);
        const offset = view.getUint32(at + 42, true);
        const name = decoder.decode(
            bytes.subarray(at + 46, at + 46 + nameLength),
        );
        entries.push({ name, method, size, offset });
        at += 46 + nameLength + extra + comment;
    }

    return entries;
}

/** Der Inhalt eines Eintrags als Text — gespeichert oder mit Deflate gepackt. */
export async function readEntry(
    bytes: Uint8Array,
    entry: Entry,
): Promise<string | null> {
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    if (view.getUint32(entry.offset, true) !== LOCAL) {
        return null;
    }
    const start =
        entry.offset +
        30 +
        view.getUint16(entry.offset + 26, true) +
        view.getUint16(entry.offset + 28, true);
    const data = bytes.slice(start, start + entry.size);
    if (entry.method === 0) {
        return new TextDecoder().decode(data);
    }
    if (entry.method !== 8 || typeof DecompressionStream === 'undefined') {
        return null;
    }
    const stream = new Blob([data])
        .stream()
        .pipeThrough(new DecompressionStream('deflate-raw'));

    return new Response(stream).text();
}

function parse(text: string | null): Record<string, unknown> | null {
    if (text === null) {
        return null;
    }
    try {
        const value: unknown = JSON.parse(text);

        return value !== null &&
            typeof value === 'object' &&
            !Array.isArray(value)
            ? (value as Record<string, unknown>)
            : null;
    } catch {
        return null;
    }
}

const text = (value: unknown): string | null =>
    typeof value === 'string' && value.trim() !== '' ? value.trim() : null;

/** Art, Name und Version eines Pakets aus seinen Bytes. */
export async function inspectPackage(
    bytes: Uint8Array,
): Promise<PackageResult> {
    const entries = zipEntries(bytes);
    if (entries === null) {
        return { ok: false, reason: 'zip' };
    }
    const find = (name: string): Entry | undefined =>
        entries.find((e) => e.name === name);
    const manifest = find('manifest.json');
    if (manifest) {
        const data = parse(await readEntry(bytes, manifest));
        const name = text(data?.name);
        if (data && name) {
            const theme = find(`${name}/theme.json`) ?? find('theme.json');
            const meta = theme ? parse(await readEntry(bytes, theme)) : null;

            return {
                ok: true,
                info: {
                    kind: 'theme',
                    name,
                    version: text(data.version) ?? text(meta?.version) ?? '',
                    label: text(meta?.label),
                    description: text(meta?.description),
                },
            };
        }
    }
    const composer =
        find('composer.json') ??
        entries.find((e) => /^[^/]+\/composer\.json$/.test(e.name));
    if (composer) {
        const data = parse(await readEntry(bytes, composer));
        const name = text(data?.name);
        if (data && name) {
            return {
                ok: true,
                info: {
                    kind: 'plugin',
                    name,
                    version: text(data.version) ?? '',
                    label: null,
                    description: text(data.description),
                },
            };
        }
    }

    return { ok: false, reason: 'inhalt' };
}

/** Eine Datei aus dem Formular prüfen. */
export async function inspectFile(file: File): Promise<PackageResult> {
    return inspectPackage(new Uint8Array(await file.arrayBuffer()));
}
