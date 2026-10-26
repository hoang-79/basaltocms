/**
 * TB 50: die Aufgabe des Spamschutzes im Browser lösen (Proof of Work, wie der Löser in `ProofOfWork::field()`) — für
 * Seiten aus Vue, etwa die Registrierung. Holt die Aufgabe ohne Cookies, probiert in Päckchen zu 500 Zahlen und gibt das
 * Feld `_pow` als Base64 zurück.
 */
export interface PowChallenge {
    algorithm: string;
    challenge: string;
    salt: string;
    signature: string;
    maxnumber: number;
}

function hex(buffer: ArrayBuffer): string {
    return Array.from(new Uint8Array(buffer), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

export async function findNumber(
    challenge: PowChallenge,
    digest: (text: string) => Promise<ArrayBuffer> = defaultDigest,
): Promise<number | null> {
    for (let start = 0; start <= challenge.maxnumber; start += 500) {
        const end = Math.min(start + 500, challenge.maxnumber + 1);
        const hashes = await Promise.all(
            Array.from({ length: end - start }, (_, i) =>
                digest(challenge.salt + String(start + i)),
            ),
        );
        const found = hashes.findIndex(
            (hash) => hex(hash) === challenge.challenge,
        );
        if (found >= 0) {
            return start + found;
        }
    }

    return null;
}

function defaultDigest(text: string): Promise<ArrayBuffer> {
    return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
}

export async function solvePow(url: string, area: string): Promise<string> {
    const response = await fetch(`${url}?bereich=${encodeURIComponent(area)}`, {
        credentials: 'omit',
        headers: { Accept: 'application/json' },
    });
    const challenge = (await response.json()) as PowChallenge;
    const number = await findNumber(challenge);
    if (number === null) {
        throw new Error('pow');
    }

    return btoa(
        JSON.stringify({
            algorithm: challenge.algorithm,
            challenge: challenge.challenge,
            salt: challenge.salt,
            signature: challenge.signature,
            number,
        }),
    );
}
