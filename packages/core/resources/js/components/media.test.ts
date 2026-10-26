import { beforeEach, describe, expect, test, vi } from 'vitest';
import type { MediaUploadResult } from '../types';
import {
    createUploadEntries,
    formatBytes,
    rejectReason,
    runUpload,
    uploadCounter,
    uploadRunning,
} from './media';

const postForm = vi.hoisted(() => vi.fn());

vi.mock('../http', () => ({
    postForm,
    postJson: vi.fn(),
    requestError: (e: unknown) =>
        e instanceof Error ? e.message : 'Anfrage fehlgeschlagen',
}));

const file = (name: string, type: string, size = 1024): File => {
    const f = new File([new Uint8Array(size)], name, { type });

    return f;
};

const accepted = (name: string): MediaUploadResult => ({
    files: [
        {
            name,
            ok: true,
            id: 7,
            media: {
                id: 7,
                name,
                mime: 'image/jpeg',
                size: 1024,
                width: 10,
                height: 10,
                is_image: true,
                url: '/m/7',
                full: '/m/7/full',
                variants_state: 'pending',
                created_at: null,
                uploaded_by: null,
                folder_id: null,
                alt_missing: [],
                usages: 0,
                detail_url: '/admin/media?media=7',
            },
        },
    ],
});

beforeEach(() => postForm.mockReset());

describe('Mehrfach-Upload', () => {
    test('zwei Dateien kommen an, eine weist der Server ab — die Zustände stehen je Datei, der Zähler stimmt', async () => {
        const files = [
            file('a.jpg', 'image/jpeg'),
            file('b.png', 'image/png'),
            file('c.pdf', 'application/pdf'),
        ];
        postForm
            .mockResolvedValueOnce(accepted('a.jpg'))
            .mockResolvedValueOnce({
                files: [
                    { name: 'b.png', ok: false, error: 'Bild beschädigt.' },
                ],
            })
            .mockResolvedValueOnce(accepted('c.pdf'));
        const entries = createUploadEntries(files);
        const seen: string[] = [];

        await runUpload('/upload', files, entries, 3, (e) =>
            seen.push(`${e.name}:${e.state}`),
        );

        expect(entries.map((e) => e.state)).toEqual([
            'done',
            'rejected',
            'done',
        ]);
        expect(entries[1]?.error).toBe('Bild beschädigt.');
        expect(entries[0]?.media?.id).toBe(7);
        expect(seen).toEqual(['a.jpg:done', 'b.png:rejected', 'c.pdf:done']);
        expect(uploadCounter(entries)).toBe('2 von 3 fertig · 1 abgewiesen');
        expect(uploadRunning(entries)).toBe(false);
    });

    test('jede Datei geht einzeln als files[] mit folder_id', async () => {
        const files = [file('a.jpg', 'image/jpeg'), file('b.png', 'image/png')];
        postForm.mockResolvedValue(accepted('a.jpg'));

        await runUpload('/upload', files, createUploadEntries(files), 3);

        expect(postForm).toHaveBeenCalledTimes(2);
        const form = postForm.mock.calls[0]?.[1] as FormData;
        expect(form.getAll('files[]')).toHaveLength(1);
        expect(form.get('folder_id')).toBe('3');
    });

    test('ein Fehler der Anfrage weist nur diese Datei ab — die nächste läuft weiter', async () => {
        const files = [file('a.jpg', 'image/jpeg'), file('b.png', 'image/png')];
        postForm
            .mockRejectedValueOnce(new Error('Anfrage fehlgeschlagen (422)'))
            .mockResolvedValueOnce(accepted('b.png'));
        const entries = createUploadEntries(files);

        await runUpload('/upload', files, entries, null);

        expect(entries[0]?.state).toBe('rejected');
        expect(entries[0]?.error).toBe('Anfrage fehlgeschlagen (422)');
        expect(entries[1]?.state).toBe('done');
        expect(uploadCounter(entries)).toBe('1 von 2 fertig · 1 abgewiesen');
    });

    test('die Vorprüfung weist TIFF und zu grosse Dateien ab, ohne sie zu senden', async () => {
        const files = [
            file('hero-original.tif', 'image/tiff'),
            file('gross.jpg', 'image/jpeg', 11 * 1024 * 1024),
            file('ok.jpg', 'image/jpeg'),
        ];
        postForm.mockResolvedValue(accepted('ok.jpg'));
        const entries = createUploadEntries(files);

        expect(entries[0]?.state).toBe('rejected');
        expect(entries[0]?.error).toContain('TIF wird nicht unterstützt');
        expect(entries[1]?.error).toContain('Grösser als 10 MB');
        expect(rejectReason(files[2]!)).toBeNull();

        await runUpload('/upload', files, entries, null);

        expect(postForm).toHaveBeenCalledTimes(1);
        expect(uploadCounter(entries)).toBe('1 von 3 fertig · 2 abgewiesen');
    });

    test('der Zähler ohne Abweisung nennt nur die Fertigen', () => {
        const entries = createUploadEntries([file('a.jpg', 'image/jpeg')]);
        expect(uploadCounter(entries)).toBe('0 von 1 fertig');
        expect(uploadRunning(entries)).toBe(true);
    });
});

describe('formatBytes', () => {
    test('kB, MB und GB mit Komma', () => {
        expect(formatBytes(14 * 1024)).toBe('14 kB');
        expect(formatBytes(1.8 * 1024 * 1024)).toBe('1,8 MB');
        expect(formatBytes(4.9 * 1024 * 1024 * 1024)).toBe('4,9 GB');
    });
});
