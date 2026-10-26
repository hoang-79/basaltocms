import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { liveClient } from '../live/client';

/**
 * «Wer bearbeitet gerade» (TB 49): Solange der Editor offen und der Tab sichtbar ist, meldet er sich alle 20 Sekunden;
 * beim Verlassen meldet er sich ab. Über die Live-Aktualisierung kommt die Liste der anderen — jede Meldung verfällt
 * nach ihren `rest` Sekunden, auch wenn keine neue Abfrage kommt.
 */
export interface PresenceConfig {
    site: number;
    key: string;
    token: string;
    version: number;
    interval: number;
    url: string;
    me: number;
    /** Wer beim Laden schon da war. */
    people?: { id: number; name: string; rest: number }[];
}

export interface PresentPerson {
    id: number;
    name: string;
}

const HEARTBEAT = 20_000;

/** Die anderen aus den Nutzdaten — ohne die eigene Person, mit Ablauf. */
export function othersFrom(
    data: Record<string, unknown> | null,
    me: number,
    now: number,
): { person: PresentPerson; until: number }[] {
    const list = Array.isArray(data?.personen)
        ? (data.personen as unknown[])
        : [];

    return list.flatMap((entry) => {
        const { id, name, rest } = (entry ?? {}) as {
            id?: unknown;
            name?: unknown;
            rest?: unknown;
        };

        return typeof id === 'number' &&
            id !== me &&
            typeof name === 'string' &&
            name !== ''
            ? [
                  {
                      person: { id, name },
                      until:
                          now +
                          Math.min(120, Math.max(1, Number(rest) || 45)) * 1000,
                  },
              ]
            : [];
    });
}

export function usePresence(
    config: PresenceConfig | null | undefined,
): Ref<PresentPerson[]> {
    const others = ref<PresentPerson[]>([]);
    if (!config) {
        return others;
    }
    let entries: { person: PresentPerson; until: number }[] = [];
    let heartbeat: ReturnType<typeof setInterval> | undefined;
    let expiry: ReturnType<typeof setTimeout> | undefined;
    let stopWatch: (() => void) | null = null;

    const send = (aktiv: boolean): void => {
        if (!aktiv) {
            // Beim Verlassen: ohne auf die Antwort zu warten — `keepalive` überlebt das Schliessen des Tabs; das
            // Token kommt wie bei Inertia aus dem Cookie XSRF-TOKEN
            const token = decodeURIComponent(
                /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie)?.[1] ?? '',
            );
            void fetch(config.url, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ aktiv: false }),
            }).catch(() => undefined);

            return;
        }
        void http
            .getClient()
            .request({
                method: 'post',
                url: config.url,
                data: { aktiv },
                headers: { Accept: 'application/json' },
            })
            .catch(() => undefined);
    };
    const render = (): void => {
        const now = Date.now();
        entries = entries.filter((entry) => entry.until > now);
        others.value = entries.map((entry) => entry.person);
        clearTimeout(expiry);
        if (entries.length > 0) {
            expiry = setTimeout(
                render,
                Math.max(
                    500,
                    Math.min(...entries.map((entry) => entry.until)) -
                        now +
                        100,
                ),
            );
        }
    };
    const visible = (): void => {
        if (document.visibilityState === 'visible') {
            send(true);
        }
    };
    const leave = (): void => send(false);

    onMounted(() => {
        entries = othersFrom(
            { personen: config.people ?? [] },
            config.me,
            Date.now(),
        );
        render();
        send(true);
        heartbeat = setInterval(() => {
            if (document.visibilityState === 'visible') {
                send(true);
            }
        }, HEARTBEAT);
        stopWatch = liveClient().watch(
            config.site,
            config.key,
            config.token,
            config.version,
            (change) => {
                entries = othersFrom(change.data, config.me, Date.now());
                render();
            },
            config.interval,
        );
        document.addEventListener('visibilitychange', visible);
        window.addEventListener('pagehide', leave);
    });
    onBeforeUnmount(() => {
        clearInterval(heartbeat);
        clearTimeout(expiry);
        stopWatch?.();
        document.removeEventListener('visibilitychange', visible);
        window.removeEventListener('pagehide', leave);
        send(false);
    });

    return others;
}
