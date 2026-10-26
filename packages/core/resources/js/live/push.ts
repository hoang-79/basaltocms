import Echo from 'laravel-echo';
import Pusher, { type ChannelAuthorizationCallback } from 'pusher-js';
import type { LiveClient } from './client';

/**
 * CR-008 (LV-11): Push über das Pusher-Protokoll (Reverb, Soketi). Wird nur geladen, wenn die Seite Verbindungsdaten
 * trägt (`#basalto-live-push`). Jeder beobachtete Schlüssel wird ein privater Kanal `basalto.<Site>.<Schlüssel>`; die
 * Anmeldung prüft der Server über die Unterschrift des Schlüssels (`/_live/auth`, ohne Sitzung). Ein Signal löst sofort
 * eine Abfrage aus — der Inhalt kommt wie immer über die gewöhnliche Adresse.
 */
type AuthData = Parameters<ChannelAuthorizationCallback>[1];

export interface PushConfig {
    key: string;
    host: string;
    port: number;
    scheme: string;
}

declare global {
    interface Window {
        basaltoLivePush?: Echo<'reverb'>;
    }
}

/** Eine Verbindung je Seite — auch wenn Insel und Glocke beide Push wollen. */
export function connectPush(
    config: PushConfig,
    client: LiveClient,
    authEndpoint = '/_live/auth',
): Echo<'reverb'> {
    window.basaltoLivePush ??= createEcho(config, client, authEndpoint);

    return window.basaltoLivePush;
}

function createEcho(
    config: PushConfig,
    client: LiveClient,
    authEndpoint: string,
): Echo<'reverb'> {
    const tokens = new Map<string, string>();
    const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            endpoint: authEndpoint,
            transport: 'ajax',
            customHandler: (
                params: { socketId: string; channelName: string },
                callback: ChannelAuthorizationCallback,
            ) => {
                fetch(authEndpoint, {
                    method: 'POST',
                    credentials: 'omit',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        socket_id: params.socketId,
                        channel_name: params.channelName,
                        token: tokens.get(params.channelName) ?? '',
                    }),
                })
                    .then((response) =>
                        response.ok
                            ? response.json()
                            : Promise.reject(new Error(`${response.status}`)),
                    )
                    .then((data: AuthData) => callback(null, data))
                    .catch((error: Error) => callback(error, null));
            },
        },
    });
    const pusher = (echo.connector as unknown as { pusher: Pusher }).pusher;
    pusher.connection.bind('state_change', (states: { current: string }) =>
        client.setPushActive(states.current === 'connected'),
    );
    client.onWatch(({ site, key, token }) => {
        const name = `basalto.${site}.${key}`;
        tokens.set(`private-${name}`, token);
        echo.private(name).listen('.signal', () => client.nudge());
    });

    return echo;
}
