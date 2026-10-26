import { describe, expect, it, vi } from 'vitest';
import { LiveClient, type LiveChange } from './client';

function response(status: number, body?: unknown): Response {
    return new Response(body === undefined ? null : JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('LiveClient', () => {
    it('fragt die bekannten Versionen ab und meldet nur Neues', async () => {
        const fetcher = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(response(204))
            .mockResolvedValueOnce(
                response(200, {
                    v: { 'forum.thema.4': 20 },
                    d: { 'forum.thema.4': { anzahl: 2 } },
                }),
            );
        const client = new LiveClient('/_live', fetcher, null);
        const changes: LiveChange[] = [];
        client.watch(3, 'forum.thema.4', 'abc', 10, (c) => changes.push(c));

        await client.poll();
        expect(changes).toEqual([]);
        expect(fetcher.mock.calls[0]?.[0] as string).toBe(
            '/_live?s=3&k%5B%5D=forum.thema.4%7Eabc%7E10',
        );
        expect(fetcher.mock.calls[0]?.[1]).toMatchObject({
            credentials: 'omit',
        });

        await client.poll();
        expect(changes).toEqual([
            { key: 'forum.thema.4', version: 20, data: { anzahl: 2 } },
        ]);
        // Die neue Version zählt ab jetzt als bekannt
        fetcher.mockResolvedValueOnce(response(204));
        await client.poll();
        expect(fetcher.mock.calls[2]?.[0] as string).toContain('%7E20');
    });

    it('wartet nach Fehlern länger und meldet ab', async () => {
        const fetcher = vi.fn<typeof fetch>().mockResolvedValue(response(500));
        const client = new LiveClient('/_live', fetcher, null);
        const stop = client.watch(1, 'konto.5', 't', 0, () => undefined, 15);
        await client.poll();
        expect((client as unknown as { backoff: number }).backoff).toBe(30);
        await client.poll();
        expect((client as unknown as { backoff: number }).backoff).toBe(60);
        stop();
        expect(client.keys()).toEqual([]);
        await client.poll();
        expect(fetcher).toHaveBeenCalledTimes(2);
    });
});
