import { describe, expect, it, vi } from 'vitest';

vi.mock('@laravel/passkeys', () => {
    class PasskeyError extends Error {}

    return {
        Passkeys: { isSupported: () => false },
        PasskeyError,
        InvalidDomainError: class extends PasskeyError {},
        NotSupportedError: class extends PasskeyError {},
        PasskeyExistsError: class extends PasskeyError {},
        UserCancelledError: class extends PasskeyError {},
    };
});

const { passkeyFehler, weiter } = await import('./konto');
const { PasskeyExistsError, NotSupportedError } =
    await import('@laravel/passkeys');

function ort(href: string) {
    return {
        href,
        hash: new URL(href).hash,
        assign: vi.fn(),
        reload: vi.fn(),
    };
}

describe('Passkeys im Kundenkonto', () => {
    it('lädt neu, wenn das Ziel auf derselben Seite liegt', () => {
        const hier = ort('https://muster.ch/konto');
        weiter('/konto#konto', hier);
        expect(hier.hash).toBe('#konto');
        expect(hier.reload).toHaveBeenCalledOnce();
        expect(hier.assign).not.toHaveBeenCalled();
    });

    it('geht zu einer anderen Seite', () => {
        const hier = ort('https://muster.ch/community/konto');
        weiter('/konto#konto', hier);
        expect(hier.assign).toHaveBeenCalledWith(
            'https://muster.ch/konto#konto',
        );
        expect(hier.reload).not.toHaveBeenCalled();
    });

    it('meldet Fehler in der Sprache der Seite', () => {
        const texte = {
            vorhanden: 'Schon angelegt.',
            geht: 'Geht hier nicht.',
        };
        expect(passkeyFehler(new PasskeyExistsError(), texte)).toBe(
            'Schon angelegt.',
        );
        expect(passkeyFehler(new NotSupportedError(), texte)).toBe(
            'Geht hier nicht.',
        );
        expect(passkeyFehler(new Error('Vom Server.'), texte)).toBe(
            'Vom Server.',
        );
        expect(passkeyFehler('unbekannt', texte)).toBe('Geht hier nicht.');
    });
});
