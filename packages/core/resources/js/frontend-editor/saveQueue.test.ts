import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Base, Change, QueueState, SaveResult } from './saveQueue';
import { createSaveQueue, xsrfToken } from './saveQueue';

describe('Frontend-Editor: Speichern', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    function queue(results: SaveResult[]) {
        const calls: { base: Base; changes: Change[] }[] = [];
        const states: QueueState[] = [];
        const q = createSaveQueue({
            base: { id: 1, stamp: 'a' },
            delay: 500,
            send: async (base, changes) => {
                calls.push({ base, changes });

                return (
                    results.shift() ?? {
                        ok: true,
                        base: { id: base.id, stamp: `${base.stamp}+` },
                        message: 'gespeichert',
                    }
                );
            },
            onState: (state) => states.push(state),
        });

        return { q, calls, states };
    }

    it('fasst Änderungen je Feld zusammen, speichert nach der Pause und reicht den neuen Stand weiter', async () => {
        const { q, calls, states } = queue([
            { ok: true, base: { id: 2, stamp: 'b' }, message: 'Fassung 2' },
        ]);
        q.set('title', 'A');
        q.set('title', 'AB');
        q.set('content.0.data.title', 'X');
        expect(calls).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(500);
        expect(calls).toEqual([
            {
                base: { id: 1, stamp: 'a' },
                changes: [
                    { field: 'title', value: 'AB' },
                    { field: 'content.0.data.title', value: 'X' },
                ],
            },
        ]);
        q.set('title', 'ABC');
        await q.flush();
        expect(calls[1].base).toEqual({ id: 2, stamp: 'b' });
        expect(states.at(-1)).toBe('saved');
        expect(q.hasUnsaved()).toBe(false);
    });

    it('hält bei veraltetem Stand an und behält Ungespeichertes nach einem Fehler', async () => {
        const conflict = queue([
            { ok: false, conflict: true, message: 'veraltet', fields: [] },
        ]);
        conflict.q.set('title', 'A');
        await conflict.q.flush();
        conflict.q.set('title', 'B');
        await conflict.q.flush();
        expect(conflict.calls).toHaveLength(1);
        expect(conflict.q.state()).toBe('conflict');

        const error = queue([
            {
                ok: false,
                conflict: false,
                message: 'zu lang',
                fields: ['title'],
            },
        ]);
        error.q.set('title', 'x'.repeat(400));
        await error.q.flush();
        expect(error.q.state()).toBe('error');
        expect(error.q.hasUnsaved()).toBe(true);
        error.q.set('title', 'kurz');
        await error.q.flush();
        expect(error.calls[1].changes).toEqual([
            { field: 'title', value: 'kurz' },
        ]);
        expect(error.q.hasUnsaved()).toBe(false);
    });

    it('liest das XSRF-Token aus dem Cookie', () => {
        expect(
            xsrfToken('a=1; XSRF-TOKEN=eyJpdiI6%3D%3D; laravel_session=x'),
        ).toBe('eyJpdiI6==');
        expect(xsrfToken('a=1')).toBeNull();
    });
});
