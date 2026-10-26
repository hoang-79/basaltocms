import { afterEach, describe, expect, it, vi } from 'vitest';
import { typingText } from '../live/markup';
import { reportTyping } from './typing';

describe('typingText', () => {
    const texts = {
        eins: ':name schreibt …',
        zwei: ':a und :b schreiben …',
        mehr: ':count Personen schreiben …',
    };

    it('nennt eine, zwei oder zählt mehr Personen', () => {
        expect(typingText([], texts)).toBe('');
        expect(typingText(['Anna'], texts)).toBe('Anna schreibt …');
        expect(typingText(['Anna', 'Ben'], texts)).toBe(
            'Anna und Ben schreiben …',
        );
        expect(typingText(['Anna', 'Ben', 'Chris'], texts)).toBe(
            '3 Personen schreiben …',
        );
    });
});

describe('reportTyping', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('meldet höchstens alle vier Sekunden und nach sechs Sekunden Pause das Ende', () => {
        vi.useFakeTimers();
        const sent: string[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn((_url: string, init: RequestInit) => {
                const value = (init.body as FormData).get('aktiv');
                sent.push(typeof value === 'string' ? value : '');

                return Promise.resolve(new Response(null, { status: 204 }));
            }),
        );
        let onUpdate = (): void => undefined;
        const editor = {
            isFocused: true,
            on: (_event: string, callback: () => void) => {
                onUpdate = callback;
            },
            off: vi.fn(),
        };
        let now = 0;
        reportTyping(
            editor as never,
            {
                url: '/community/schreibt',
                key: 'schreibt.forum.1',
                token: 'x'.repeat(32),
            },
            null,
            () => 'csrf',
            () => now,
        );

        onUpdate();
        now = 1000;
        onUpdate();
        expect(sent).toEqual(['1']);
        now = 4100;
        onUpdate();
        expect(sent).toEqual(['1', '1']);
        vi.advanceTimersByTime(6000);
        expect(sent).toEqual(['1', '1', '0']);
        // Ohne Fokus (etwa ein wiederhergestellter Entwurf) zählt nichts
        editor.isFocused = false;
        onUpdate();
        expect(sent).toEqual(['1', '1', '0']);
    });
});
