/**
 * CR-008: Live-Aktualisierung im Browser. Beobachtet Schlüssel (`forum.thema.45` …) einer Site und fragt ihre
 * Versionen über `/_live` ab — nur solange der Tab sichtbar ist, sofort beim Zurückkehren, nach Fehlern mit längeren
 * Pausen. Ändert sich eine Version, bekommen die Beobachter die neue Version und kleine Nutzdaten (z. B. Zähler); den
 * Inhalt holen sie selbst über die gewöhnliche Adresse. Push (TB 46) meldet sich über `nudge()`.
 */
export type LiveData = Record<string, unknown>;

export interface LiveChange {
    key: string;
    version: number;
    data: LiveData | null;
}

type Listener = (change: LiveChange) => void;

interface Watch {
    site: number;
    key: string;
    token: string;
    version: number;
    listeners: Set<Listener>;
}

const MAX_BACKOFF = 300;

export class LiveClient {
    private readonly watches = new Map<string, Watch>();
    private interval = 15;
    private backoff = 0;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private running = false;
    private inFlight = false;
    private pushActive = false;
    private background = false;
    private readonly watchListeners = new Set<
        (watch: { site: number; key: string; token: string }) => void
    >();

    constructor(
        private readonly endpoint = '/_live',
        private readonly fetcher: typeof fetch = (...args) => fetch(...args),
        private readonly doc: Document | null = typeof document === 'undefined'
            ? null
            : document,
    ) {}

    /** Einen Schlüssel beobachten; liefert eine Funktion zum Abmelden. */
    watch(
        site: number,
        key: string,
        token: string,
        version: number,
        listener: Listener,
        interval?: number,
    ): () => void {
        const id = `${site}|${key}`;
        let watch = this.watches.get(id);
        if (!watch) {
            watch = { site, key, token, version, listeners: new Set() };
            this.watches.set(id, watch);
            this.watchListeners.forEach((listener) =>
                listener({ site, key, token }),
            );
        }
        watch.version = Math.max(watch.version, version);
        watch.listeners.add(listener);
        if (interval && interval > 0) {
            this.interval = Math.min(this.interval, interval);
        }
        this.start();

        return () => {
            watch.listeners.delete(listener);
            if (watch.listeners.size === 0) {
                this.watches.delete(id);
            }
        };
    }

    /** Sofort abfragen — etwa wenn ein Push-Signal kam oder der Tab wieder sichtbar ist. */
    nudge(): void {
        this.schedule(0);
    }

    /** Push meldet sich an (neue Schlüssel abonnieren) — mit allen bisherigen. */
    onWatch(
        listener: (watch: { site: number; key: string; token: string }) => void,
    ): void {
        this.watchListeners.add(listener);
        this.keys().forEach(listener);
    }

    /** Solange Push verbunden ist, reicht eine Abfrage je vierfachem Takt als Netz. */
    setPushActive(active: boolean): void {
        const changed = this.pushActive !== active;
        this.pushActive = active;
        if (changed && !active) {
            this.nudge();
        }
    }

    /**
     * Auch bei verborgenem Tab weiterfragen (alle 60 s, mit Push sofort) — für Desktop-Benachrichtigungen, die nur dann
     * Sinn haben.
     */
    setBackground(active: boolean): void {
        this.background = active;
    }

    /** Die bekannten Schlüssel (für Push: welche Kanäle zu abonnieren sind). */
    keys(): { site: number; key: string; token: string }[] {
        return [...this.watches.values()].map(({ site, key, token }) => ({
            site,
            key,
            token,
        }));
    }

    async poll(): Promise<void> {
        if (this.inFlight || this.watches.size === 0) {
            return;
        }
        this.inFlight = true;
        try {
            const bySite = new Map<number, Watch[]>();
            this.watches.forEach((watch) =>
                bySite.set(watch.site, [
                    ...(bySite.get(watch.site) ?? []),
                    watch,
                ]),
            );
            for (const [site, watches] of bySite) {
                const query = new URLSearchParams({ s: String(site) });
                watches.forEach((w) =>
                    query.append('k[]', `${w.key}~${w.token}~${w.version}`),
                );
                const response = await this.fetcher(
                    `${this.endpoint}?${query.toString()}`,
                    {
                        credentials: 'omit',
                        headers: { Accept: 'application/json' },
                    },
                );
                if (response.status === 204) {
                    continue;
                }
                if (!response.ok) {
                    throw new Error(`Live ${response.status}`);
                }
                const body = (await response.json()) as {
                    v?: Record<string, number>;
                    d?: Record<string, LiveData>;
                };
                Object.entries(body.v ?? {}).forEach(([key, version]) => {
                    const watch = this.watches.get(`${site}|${key}`);
                    if (!watch || version <= watch.version) {
                        return;
                    }
                    watch.version = version;
                    const change = {
                        key,
                        version,
                        data: body.d?.[key] ?? null,
                    };
                    watch.listeners.forEach((listener) => listener(change));
                    this.doc?.dispatchEvent(
                        new CustomEvent('basalto:live', { detail: change }),
                    );
                });
            }
            this.backoff = 0;
        } catch {
            this.backoff = Math.min(
                MAX_BACKOFF,
                Math.max(this.interval * 2, this.backoff * 2),
            );
        } finally {
            this.inFlight = false;
        }
    }

    private start(): void {
        if (this.running) {
            return;
        }
        this.running = true;
        this.doc?.addEventListener('visibilitychange', () => {
            if (this.visible()) {
                this.nudge();
            }
        });
        this.schedule(this.interval);
    }

    private schedule(seconds: number): void {
        if (this.timer !== null) {
            clearTimeout(this.timer);
        }
        this.timer = setTimeout(() => {
            this.timer = null;
            void this.tick();
        }, seconds * 1000);
    }

    private async tick(): Promise<void> {
        const visible = this.visible();
        if (visible || this.background) {
            await this.poll();
        }
        const base = this.interval * (this.pushActive ? 4 : 1);
        this.schedule(
            this.backoff > 0
                ? this.backoff
                : visible
                  ? base
                  : Math.max(60, base),
        );
    }

    private visible(): boolean {
        return !this.doc || this.doc.visibilityState !== 'hidden';
    }
}

declare global {
    interface Window {
        basaltoLive?: LiveClient;
    }
}

/** Ein Client je Seite — auch wenn mehrere Bündel ihn einbinden (Insel im Frontend, Glocke im Backend). */
export function liveClient(): LiveClient {
    window.basaltoLive ??= new LiveClient();

    return window.basaltoLive;
}
