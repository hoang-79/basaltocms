import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { findNumber } from './pow';

const digest = (text: string): Promise<ArrayBuffer> => {
    const buffer = createHash('sha256').update(text).digest();

    return Promise.resolve(
        buffer.buffer.slice(
            buffer.byteOffset,
            buffer.byteOffset + buffer.byteLength,
        ),
    );
};

describe('findNumber', () => {
    it('findet die Zahl, deren Hash mit dem Salz die Aufgabe ergibt', async () => {
        const salt = 'abc?bereich=registrierung&bis=1';
        const challenge = createHash('sha256')
            .update(salt + '1234')
            .digest('hex');
        expect(
            await findNumber(
                {
                    algorithm: 'SHA-256',
                    challenge,
                    salt,
                    signature: 'x',
                    maxnumber: 2000,
                },
                digest,
            ),
        ).toBe(1234);
    });

    it('gibt null, wenn keine Zahl passt', async () => {
        expect(
            await findNumber(
                {
                    algorithm: 'SHA-256',
                    challenge: '0'.repeat(64),
                    salt: 's',
                    signature: 'x',
                    maxnumber: 600,
                },
                digest,
            ),
        ).toBeNull();
    });
});
