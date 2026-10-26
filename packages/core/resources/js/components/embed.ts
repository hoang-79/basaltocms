/**
 * Läuft die Seite in einem Rahmen — etwa im Modal der Website (basaltocms.com öffnet die Registrierung über der
 * Startseite)? Dann zeigt der Rahmen der Plattform nur das Formular, und Links aus dem Ablauf hinaus öffnen das ganze
 * Fenster (`target="_top"`). Ein fremder Rahmen, den der Browser nicht befragen lässt, zählt als Rahmen.
 */
export function isEmbedded(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    try {
        return window.self !== window.top;
    } catch {
        return true;
    }
}
