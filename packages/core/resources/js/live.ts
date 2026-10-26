import { enhanceKompass } from './community/kompass';
import { type LiveClient, liveClient } from './live/client';
import {
    desktopEnabled,
    desktopSupported,
    disableDesktop,
    enableDesktop,
} from './live/desktop';
import { enhanceLive } from './live/markup';
import type { PushConfig } from './live/push';

/**
 * CR-008: Insel der Live-Aktualisierung im Frontend — ein eigenes, kleines Bündel, damit lesende Gäste nicht den Editor
 * mitladen. Übernimmt alle Stellen mit `data-live` und neu eingefügte Inhalte (Ereignis `basalto:inhalt`).
 */
/** Schalter für Desktop-Benachrichtigungen (`[data-live-desktop]` mit Texten `data-an` und `data-aus`). */
function enhanceDesktop(client: LiveClient): void {
    client.setBackground(desktopEnabled());
    document
        .querySelectorAll<HTMLElement>('[data-live-desktop]')
        .forEach((element) => {
            const button = element.querySelector('button');
            if (!desktopSupported() || !button) {
                return;
            }
            const label = (): void => {
                button.textContent = desktopEnabled()
                    ? (element.dataset.aus ?? '')
                    : (element.dataset.an ?? '');
            };
            label();
            element.hidden = false;
            button.addEventListener('click', () => {
                void (
                    desktopEnabled()
                        ? Promise.resolve(disableDesktop())
                        : enableDesktop()
                ).then(() => {
                    client.setBackground(desktopEnabled());
                    label();
                });
            });
        });
}

function start(): void {
    const client = liveClient();
    enhanceLive(document, client);
    enhanceDesktop(client);
    // TB 47: Kompass und Tastenkürzel am Thema
    enhanceKompass(document);
    // Push nur, wenn eingerichtet — Echo und pusher-js kommen dann als eigener Teil nach
    const push = document.getElementById('basalto-live-push');
    if (push?.textContent) {
        const config = JSON.parse(push.textContent) as PushConfig;
        void import('./live/push').then(({ connectPush }) =>
            connectPush(config, client),
        );
    }
    document.addEventListener('basalto:inhalt', (event) => {
        const root = (event as CustomEvent<{ root?: ParentNode }>).detail?.root;
        if (root) {
            enhanceLive(root, client);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
