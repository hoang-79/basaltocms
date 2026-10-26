/**
 * CR-008 (LV-10): Desktop-Benachrichtigungen auf Wunsch — die Erlaubnis gibt der Browser, die Wahl bleibt im Browser
 * (`localStorage`). Gezeigt wird nur, wenn der Tab nicht im Vordergrund ist; sonst reichen Glocke und Zähler.
 */
const KEY = 'basalto-desktop';

export function desktopSupported(): boolean {
    return typeof window !== 'undefined' && 'Notification' in window;
}

export function desktopEnabled(): boolean {
    return (
        desktopSupported() &&
        Notification.permission === 'granted' &&
        window.localStorage.getItem(KEY) === '1'
    );
}

export async function enableDesktop(): Promise<boolean> {
    if (!desktopSupported()) {
        return false;
    }
    const permission = await Notification.requestPermission();
    if (permission === 'granted') {
        window.localStorage.setItem(KEY, '1');
    }

    return permission === 'granted';
}

export function disableDesktop(): void {
    window.localStorage.removeItem(KEY);
}

/** Eine Benachrichtigung zeigen; ein Klick öffnet ihr Ziel im Tab. Gleiche `tag` ersetzen sich. */
export function showDesktop(
    title: string,
    body: string | null,
    url: string,
    tag: string,
): void {
    if (
        !desktopEnabled() ||
        (document.visibilityState === 'visible' && document.hasFocus())
    ) {
        return;
    }
    const notification = new Notification(title, {
        body: body ?? undefined,
        tag,
    });
    notification.onclick = () => {
        window.focus();
        window.location.assign(url);
        notification.close();
    };
}
