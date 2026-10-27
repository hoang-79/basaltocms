import { router } from '@inertiajs/vue3';
import type { InertiaForm } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import { t } from '../i18n';
import { intlLocale } from '../support';

/**
 * Autosave für Formulare (Wunsch Hoang 16.10.2026): Jede Änderung speichert sich nach einer kurzen Pause selbst —
 * kein «Speichern»-Knopf mehr, wo ein Datensatz schon besteht. Rückmeldung dezent, aber nicht übersehbar:
 * am Feld ein Häkchen «gespeichert» (`savedFields`), unten rechts eine kleine Meldung (`autosaveToast`), die bei
 * Fehlern stehen bleibt.
 *
 * Technisch ist es derselbe Inertia-Besuch wie vorher beim Knopf (`PUT`/`POST`, Weiterleitung zurück), aber
 * asynchron (eine Navigation bricht ihn nicht ab), mit `preserveState` und nur den Fehlern als Antwort — die Seite
 * zeichnet nichts neu, was gerade getippt wird.
 */

export type AutosaveStatus =
    | 'idle'
    | 'pending'
    | 'waiting'
    | 'saving'
    | 'saved'
    | 'error';

/** Kopfzeile jeder Autosave-Anfrage: Protokoll und Verlauf fassen eine Folge davon zusammen (CR-013). */
export const AUTOSAVE_HEADER = 'X-Basalto-Autosave';

/** Die gemeinsame Meldung unten rechts — eine für alle Formulare der Seite. */
export const autosaveToast = reactive<{
    status: AutosaveStatus;
    text: string;
    at: string | null;
    seq: number;
}>({
    status: 'idle',
    text: '',
    at: null,
    seq: 0,
});

/** Schlüssel (mit Punkt für Verschachteltes), deren Wert sich geändert hat. */
export function changedKeys(
    before: unknown,
    after: unknown,
    prefix = '',
): string[] {
    if (
        typeof before === 'object' &&
        before !== null &&
        !Array.isArray(before) &&
        typeof after === 'object' &&
        after !== null &&
        !Array.isArray(after)
    ) {
        const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
        const out: string[] = [];
        for (const key of keys) {
            out.push(
                ...changedKeys(
                    (before as Record<string, unknown>)[key],
                    (after as Record<string, unknown>)[key],
                    prefix ? `${prefix}.${key}` : key,
                ),
            );
        }

        return out;
    }
    // Listen je Eintrag vergleichen — so trifft eine Änderung in Block 3 nur «content.2», nicht alle Blöcke
    if (Array.isArray(before) && Array.isArray(after)) {
        const out: string[] = [];
        for (let i = 0; i < Math.max(before.length, after.length); i++) {
            out.push(
                ...changedKeys(
                    before[i],
                    after[i],
                    prefix ? `${prefix}.${i}` : String(i),
                ),
            );
        }

        return out;
    }

    return JSON.stringify(before) === JSON.stringify(after) ? [] : [prefix];
}

/** Die Beschriftung zu einem Schlüssel über den längsten passenden Präfix («values.content.1.data.body.content.0» → «Fliesstext»). */
export function labelFor(
    key: string,
    labels: Record<string, string> | undefined,
): string | null {
    if (!labels) {
        return null;
    }
    const parts = key.split('.');
    for (let n = parts.length; n > 0; n--) {
        const candidate = parts.slice(0, n).join('.');
        if (labels[candidate]) {
            return labels[candidate] ?? null;
        }
    }

    return null;
}

/** Ist ein Feld (oder etwas darin) gerade gespeichert worden? «alt» trifft «alt.de», «content.0» trifft «content.0.data.title». */
export function isSaved(saved: string[], key: string): boolean {
    return saved.some(
        (k) => k === key || k.startsWith(`${key}.`) || key.startsWith(`${k}.`),
    );
}

/** Wert an einem Punkt-Pfad («fields.2.key»). */
export function getPath(data: unknown, path: string): unknown {
    return path
        .split('.')
        .reduce<unknown>(
            (value, part) =>
                value !== null && typeof value === 'object'
                    ? (value as Record<string, unknown>)[part]
                    : undefined,
            data,
        );
}

/** Wert an einem Punkt-Pfad setzen (nur wo der Weg dorthin besteht). */
export function setPath(data: unknown, path: string, value: unknown): void {
    const parts = path.split('.');
    const last = parts.pop();
    const target = parts.reduce<unknown>(
        (current, part) =>
            current !== null && typeof current === 'object'
                ? (current as Record<string, unknown>)[part]
                : undefined,
        data,
    );
    if (last !== undefined && target !== null && typeof target === 'object') {
        (target as Record<string, unknown>)[last] = value;
    }
}

/** Alle Pfade in `data`, die auf ein Muster mit «*» passen («fields.*.key» → «fields.0.key», «fields.1.key»). */
export function expandPath(pattern: string, data: unknown): string[] {
    const parts = pattern.split('.');
    let paths: { path: string; value: unknown }[] = [{ path: '', value: data }];
    for (const part of parts) {
        const next: { path: string; value: unknown }[] = [];
        for (const { path, value } of paths) {
            if (value === null || typeof value !== 'object') {
                continue;
            }
            const keys = part === '*' ? Object.keys(value as object) : [part];
            for (const key of keys) {
                if (key in (value as object)) {
                    next.push({
                        path: path ? `${path}.${key}` : key,
                        value: (value as Record<string, unknown>)[key],
                    });
                }
            }
        }
        paths = next;
    }

    return paths.map((p) => p.path);
}

function clone<V>(value: V): V {
    return value === undefined
        ? value
        : (JSON.parse(JSON.stringify(value)) as V);
}

function now(): string {
    return new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date());
}

type Options<T> = {
    url: string | (() => string);
    method?: 'put' | 'post';
    /** Pause nach der letzten Änderung, bevor gespeichert wird */
    delay?: number;
    /** Props, die nach dem Speichern neu geladen werden sollen (Standard: keine — nur Fehler) */
    only?: string[];
    /** Beschriftung für die Meldung: Feldschlüssel → Wort («first_name» → «Vorname») */
    labels?: Record<string, string>;
    /** Wann nicht automatisch gespeichert wird (z. B. Formular gesperrt) */
    enabled?: () => boolean;
    onSaved?: (keys: string[]) => void;
    /**
     * Speicherart «beim Verlassen» (CR-013): Diese Felder (Punkt-Pfade, «*» für Listen) gehen erst mit Enter oder beim
     * Verlassen des Felds mit; Esc nimmt die Änderung zurück. Für Kennungen, Pfade, Geheimnisse, Preise.
     */
    onLeave?: string[];
    /**
     * Abgelehnte Felder blockieren nicht (CR-013): Für ein Feld, das der Server abgelehnt hat, geht beim nächsten
     * Speichern der zuletzt gespeicherte Wert mit, bis es neu bearbeitet wird; der Fehler bleibt am Feld. Mit
     * `onLeave` immer an. Setzt `form.transform` — eine eigene Umformung gehört dann in `transform`.
     */
    keepRejected?: boolean;
    transform?: (data: T) => object;
};

export function useAutosave<T extends object>(
    form: InertiaForm<T>,
    options: Options<T>,
) {
    const delay = options.delay ?? 700;
    const leavePatterns = options.onLeave ?? [];
    const partial = leavePatterns.length > 0 || options.keepRejected === true;
    /** Felder «beim Verlassen»: Pfad → Wert (JSON), der mitgehen darf */
    const committed = new Map<string, string>();
    /** Abgelehnte Felder: Pfad → abgelehnter Wert (JSON) und Meldung */
    const rejected = new Map<string, { json: string; message: string }>();
    const waitingPaths = ref<string[]>([]);
    const savedFields = ref<string[]>([]);
    const status = ref<AutosaveStatus>('idle');
    let lastSaved = JSON.parse(JSON.stringify(form.data())) as T;
    let lastSavedJson = JSON.stringify(lastSaved);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let clearTimer: ReturnType<typeof setTimeout> | undefined;
    let queued = false;

    /** Was gespeichert werden darf: Felder «beim Verlassen» erst nach dem Verlassen, abgelehnte erst neu bearbeitet. */
    function payload(): T {
        const data = clone(form.data()) as T;
        if (!partial) {
            return data;
        }
        const waiting: string[] = [];
        for (const pattern of leavePatterns) {
            for (const path of expandPath(pattern, data)) {
                const current = JSON.stringify(getPath(data, path));
                const saved = getPath(lastSaved, path);
                if (
                    saved === undefined ||
                    committed.get(path) === current ||
                    JSON.stringify(saved) === current
                ) {
                    continue;
                }
                setPath(data, path, clone(saved));
                waiting.push(path);
            }
        }
        for (const [path, entry] of rejected) {
            const saved = getPath(lastSaved, path);
            if (
                saved !== undefined &&
                JSON.stringify(getPath(data, path)) === entry.json
            ) {
                setPath(data, path, clone(saved));
            }
        }
        waitingPaths.value = waiting;

        return data;
    }

    function label(keys: string[]): string {
        const words = [
            ...new Set(
                keys
                    .map(
                        (k) =>
                            options.labels?.[k] ??
                            options.labels?.[k.split('.')[0] ?? ''] ??
                            null,
                    )
                    .filter((w): w is string => !!w),
            ),
        ];

        return words.length === 0
            ? t('Änderung')
            : words.length > 2
              ? `${words.slice(0, 2).join(', ')} …`
              : words.join(', ');
    }

    let waiters: ((ok: boolean) => void)[] = [];

    /**
     * Sofort speichern, was wartet. Das Promise löst mit true auf, wenn danach alles gespeichert ist, mit false bei
     * einem Fehler oder wenn `enabled()` das Speichern noch zurückhält (z. B. unvollständige Eingabe).
     */
    function flush(): Promise<boolean> {
        if (timer) {
            clearTimeout(timer);
            timer = undefined;
        }
        if (options.enabled && !options.enabled()) {
            status.value =
                JSON.stringify(payload()) === lastSavedJson
                    ? 'idle'
                    : 'pending';

            return Promise.resolve(status.value === 'idle');
        }
        if (form.processing) {
            queued = true;

            return new Promise((resolve) => waiters.push(resolve));
        }
        const sending = payload();
        const json = JSON.stringify(sending);
        if (json === lastSavedJson) {
            if (partial) {
                // Nichts zu senden; wartende Felder und stehende Fehler bleiben, wie sie sind
                status.value =
                    waitingPaths.value.length > 0
                        ? 'waiting'
                        : rejected.size > 0
                          ? 'error'
                          : 'idle';

                return Promise.resolve(status.value !== 'error');
            }
            // Zurück auf den gespeicherten Wert: alte Fehler gelten nicht mehr
            status.value = 'idle';
            form.clearErrors();
            if (autosaveToast.status === 'error') {
                autosaveToast.status = 'idle';
                autosaveToast.seq++;
            }

            return Promise.resolve(true);
        }
        const done = new Promise<boolean>((resolve) => waiters.push(resolve));
        const keys = changedKeys(lastSaved, sending);
        status.value = 'saving';
        autosaveToast.status = 'saving';
        autosaveToast.text = t('Speichert …');
        const url =
            typeof options.url === 'function' ? options.url() : options.url;
        if (partial) {
            const shape = options.transform ?? ((data: T): object => data);
            form.transform(() => shape(sending));
        }
        form.submit(options.method ?? 'put', url, {
            headers: { [AUTOSAVE_HEADER]: '1' },
            preserveScroll: true,
            preserveState: true,
            async: true,
            only: options.only ?? ['errors'],
            onSuccess: () => {
                lastSaved = sending;
                lastSavedJson = json;
                form.defaults(sending as Partial<T>);
                if (partial) {
                    // Stehende Fehler bleiben am Feld, solange es den abgelehnten Wert zeigt
                    for (const [path, entry] of rejected) {
                        if (
                            JSON.stringify(getPath(form.data(), path)) ===
                            entry.json
                        ) {
                            (
                                form as unknown as {
                                    setError(
                                        field: string,
                                        message: string,
                                    ): void;
                                }
                            ).setError(path, entry.message);
                        } else {
                            rejected.delete(path);
                        }
                    }
                    for (const [path, value] of committed) {
                        if (JSON.stringify(getPath(sending, path)) === value) {
                            committed.delete(path);
                        }
                    }
                }
                status.value = 'saved';
                savedFields.value = keys;
                autosaveToast.status = 'saved';
                autosaveToast.text = t(':what gespeichert', {
                    what: label(keys),
                });
                autosaveToast.at = now();
                autosaveToast.seq++;
                options.onSaved?.(keys);
                if (clearTimer) {
                    clearTimeout(clearTimer);
                }
                clearTimer = setTimeout(() => {
                    savedFields.value = [];
                }, 2600);
            },
            onError: (errors) => {
                if (partial) {
                    for (const [path, message] of Object.entries(errors)) {
                        rejected.set(path, {
                            json: JSON.stringify(getPath(sending, path)),
                            message: String(message),
                        });
                    }
                }
                status.value = 'error';
                autosaveToast.status = 'error';
                autosaveToast.text = t('Nicht gespeichert — :message', {
                    message:
                        Object.values(errors)[0] ??
                        t('bitte das markierte Feld prüfen'),
                });
                autosaveToast.at = now();
                autosaveToast.seq++;
            },
            onFinish: () => {
                const ok = status.value === 'saved';
                // Nur weiter, wenn seither getippt wurde — nach einer Ablehnung nicht dieselben Daten erneut senden
                if (queued || JSON.stringify(payload()) !== json) {
                    queued = false;
                    // Wer auf das Speichern wartet (Einreichen), wartet auch auf den nächsten Durchgang
                    void flush();

                    return;
                }
                const pending = waiters;
                waiters = [];
                pending.forEach((resolve) => resolve(ok));
            },
        });

        return done;
    }

    function schedule(): void {
        if (timer) {
            clearTimeout(timer);
        }
        status.value = 'pending';
        timer = setTimeout(() => void flush(), delay);
    }

    /** Nach dem Laden neue Ausgangslage setzen (z. B. wenn Props ein Formular frisch füllen) */
    function rebase(): void {
        lastSaved = JSON.parse(JSON.stringify(form.data())) as T;
        lastSavedJson = JSON.stringify(lastSaved);
        committed.clear();
        rejected.clear();
        waitingPaths.value = [];
    }

    watch(
        () => JSON.stringify(form.data()),
        (json) => {
            if (!partial) {
                if (json !== lastSavedJson) {
                    schedule();
                }

                return;
            }
            if (JSON.stringify(payload()) !== lastSavedJson) {
                schedule();
            } else if (!form.processing) {
                status.value =
                    waitingPaths.value.length > 0
                        ? 'waiting'
                        : rejected.size > 0
                          ? 'error'
                          : 'idle';
            }
        },
    );

    /** Ein Feld «beim Verlassen» übernehmen und gleich speichern. */
    function commit(path: string): Promise<boolean> {
        committed.set(path, JSON.stringify(getPath(form.data(), path)));

        return flush();
    }

    /** Esc: zurück auf den gespeicherten Wert. */
    function revert(path: string): void {
        const saved = getPath(lastSaved, path);
        if (saved !== undefined) {
            setPath(form, path, clone(saved));
            committed.delete(path);
        }
    }

    /**
     * Für `v-bind` an `Field` oder einem Eingabefeld: Verlassen und Enter speichern, Esc nimmt zurück. Mehrzeiliger
     * Text bekommt Enter als Zeilenumbruch.
     */
    function leave(path: string) {
        return {
            onFocusout: (): void => {
                void commit(path);
            },
            onKeydown: (event: KeyboardEvent): void => {
                if (
                    event.key === 'Enter' &&
                    !(event.target instanceof HTMLTextAreaElement)
                ) {
                    event.preventDefault();
                    void commit(path);
                } else if (event.key === 'Escape') {
                    revert(path);
                }
            },
        };
    }

    /** Wartet das Feld noch auf Enter oder Verlassen? (Für den Hinweis am Feld.) */
    function waiting(path: string): boolean {
        return waitingPaths.value.some(
            (p) => p === path || p.startsWith(`${path}.`),
        );
    }

    function commitAll(): void {
        for (const path of waitingPaths.value) {
            committed.set(path, JSON.stringify(getPath(form.data(), path)));
        }
    }

    // Wer wegnavigiert, verliert nichts: was noch wartet, geht vorher raus (async — die Navigation bricht es nicht ab)
    const offBefore = router.on('before', (event) => {
        const visit = event.detail.visit as { async?: boolean };
        if (visit.async) {
            return;
        }
        // Wer wegnavigiert, verlässt auch das Feld: wartende Felder gehen mit
        if (waitingPaths.value.length > 0) {
            commitAll();
            void flush();
        } else if (timer) {
            void flush();
        }
    });
    const onUnload = (event: BeforeUnloadEvent): void => {
        if (timer || form.processing || waitingPaths.value.length > 0) {
            event.preventDefault();
        }
    };
    window.addEventListener('beforeunload', onUnload);
    onBeforeUnmount(() => {
        offBefore();
        window.removeEventListener('beforeunload', onUnload);
        if (timer) {
            void flush();
        }
    });

    return {
        savedFields,
        status,
        flush,
        rebase,
        commit,
        revert,
        leave,
        waiting,
        saved: (key: string) => isSaved(savedFields.value, key),
    };
}

/** Für Speichern ohne Formularobjekt (einzelne Werte, Reihenfolgen): die Meldung unten rechts setzen. */
export function announceSaving(): void {
    autosaveToast.status = 'saving';
    autosaveToast.text = t('Speichert …');
}

export function announceSaved(what: string): void {
    autosaveToast.status = 'saved';
    autosaveToast.text = t(':what gespeichert', { what });
    autosaveToast.at = now();
    autosaveToast.seq++;
}

export function announceError(message: string): void {
    autosaveToast.status = 'error';
    autosaveToast.text = t('Nicht gespeichert — :message', { message });
    autosaveToast.at = now();
    autosaveToast.seq++;
}

type ValueOptions = {
    /** Wohin speichern — `null` heisst: gerade nicht (z. B. nichts gewählt) */
    target: () => { method: 'put' | 'post'; url: string } | null;
    /** Props, die nach dem Speichern neu geladen werden (der Serverstand für `dirty`) */
    only: string[];
    /** Weicht der Wert vom Serverstand ab? */
    dirty: () => boolean;
    /** Darf jetzt gespeichert werden (keine Fehler beim Prüfen, vollständig)? */
    enabled?: () => boolean;
    /** Was in der Meldung unten rechts steht («Layout «default»») */
    label: () => string;
    delay?: number;
    onSaved?: () => void;
};

/**
 * Autosave für Seiten, die ihren Entwurf als `ref` halten und nach dem Speichern die Liste neu laden (Layouts, Teile,
 * Templates, Bausteine, Bildvarianten — CR-013): Gespeichert wird, was vom Serverstand abweicht, nach einer Pause; ein
 * Neuladen darf den Entwurf nur überschreiben, wenn `busy()` nein sagt (sonst ginge Getipptes verloren).
 */
export function useAutosaveValue<V>(
    read: () => V | null,
    options: ValueOptions,
) {
    const delay = options.delay ?? 1500;
    const status = ref<AutosaveStatus>('idle');
    type Target = { method: 'put' | 'post'; url: string };
    let timer: ReturnType<typeof setTimeout> | undefined;
    let sending = false;
    let lastSent = '';
    /** Wert und Ziel zum Zeitpunkt der letzten Änderung — wechselt die Auswahl, geht er noch an das alte Ziel */
    let pending: { json: string; target: Target } | null = null;

    function send(json: string, target: Target): void {
        if (json === lastSent && status.value !== 'error') {
            return;
        }
        sending = true;
        lastSent = json;
        status.value = 'saving';
        announceSaving();
        router.visit(target.url, {
            method: target.method,
            data: JSON.parse(json) as never,
            headers: { [AUTOSAVE_HEADER]: '1' },
            preserveScroll: true,
            preserveState: true,
            async: true,
            only: [...new Set([...options.only, 'errors'])],
            onSuccess: () => {
                status.value = 'saved';
                announceSaved(options.label());
                options.onSaved?.();
            },
            onError: (errors) => {
                status.value = 'error';
                announceError(
                    Object.values(errors)[0] ??
                        t('bitte das markierte Feld prüfen'),
                );
            },
            onFinish: () => {
                sending = false;
                // Seither weiter getippt: gleich den nächsten Stand
                const now = read();
                if (
                    now !== null &&
                    JSON.stringify(now) !== lastSent &&
                    options.dirty()
                ) {
                    remember();
                }
            },
        });
    }

    function flush(): void {
        if (timer) {
            clearTimeout(timer);
            timer = undefined;
        }
        if (pending === null) {
            status.value = options.dirty() ? status.value : 'idle';

            return;
        }
        if (options.enabled && !options.enabled()) {
            status.value = 'pending';

            return;
        }
        if (sending) {
            // Nach der laufenden Anfrage prüft onFinish, ob noch etwas wartet
            return;
        }
        const { json, target } = pending;
        pending = null;
        send(json, target);
    }

    /** Den jetzigen Stand zum Speichern vormerken (nach der Pause). */
    function remember(): void {
        const value = read();
        const target = options.target();
        if (value === null || target === null || !options.dirty()) {
            if (timer) {
                clearTimeout(timer);
                timer = undefined;
            }
            pending = null;
            status.value = 'idle';

            return;
        }
        pending = { json: JSON.stringify(value), target };
        if (timer) {
            clearTimeout(timer);
        }
        status.value = 'pending';
        timer = setTimeout(flush, delay);
    }

    watch(
        () => JSON.stringify(read()),
        () => {
            const target = options.target();
            // Andere Auswahl, während noch etwas wartet: das Wartende geht sofort an sein Ziel
            if (pending && timer && target?.url !== pending.target.url) {
                clearTimeout(timer);
                timer = undefined;
                const old = pending;
                pending = null;
                if (!options.enabled || options.enabled()) {
                    send(old.json, old.target);
                }
            }
            remember();
        },
    );

    // Wurde zurückgehalten (z. B. Fehler beim Prüfen) und darf jetzt: das Wartende speichern
    watch(
        () => (options.enabled ? options.enabled() : true),
        (allowed) => {
            if (allowed && pending !== null && timer === undefined) {
                timer = setTimeout(flush, delay);
            }
        },
    );

    const offBefore = router.on('before', (event) => {
        const visit = event.detail.visit as { async?: boolean };
        if (timer && !visit.async) {
            flush();
        }
    });
    const onUnload = (event: BeforeUnloadEvent): void => {
        if (timer || sending) {
            event.preventDefault();
        }
    };
    window.addEventListener('beforeunload', onUnload);
    onBeforeUnmount(() => {
        offBefore();
        window.removeEventListener('beforeunload', onUnload);
        if (timer) {
            flush();
        }
    });

    return {
        status,
        flush,
        /** Wartet noch etwas oder ist unterwegs? Dann darf kein Neuladen den Entwurf überschreiben. */
        busy: (): boolean =>
            timer !== undefined || sending || status.value === 'pending',
    };
}

/**
 * Entwurf im Browser (CR-013): Wo Text mit einem Knopf gesendet wird (Notiz, Antwort), bleibt er im Browser, bis er
 * gesendet ist — ein Neuladen oder ein versehentliches Wegklicken verliert nichts. Gespeichert wird nur Text, keine
 * Auswahl; leer fällt der Entwurf weg. Nach dem Senden `clear()`.
 */
export function useDraft<T extends object>(
    form: InertiaForm<T>,
    key: () => string,
    fields: (keyof T & string)[],
) {
    const storageKey = (): string => `basalto.entwurf.${key()}`;
    const restored = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    function read(): void {
        try {
            const raw = window.localStorage.getItem(storageKey());
            const saved = raw
                ? (JSON.parse(raw) as Record<string, unknown>)
                : null;
            const empty = fields.every(
                (f) => String(form[f as keyof typeof form] ?? '').trim() === '',
            );
            if (saved && empty) {
                for (const f of fields) {
                    if (typeof saved[f] === 'string') {
                        (form as Record<string, unknown>)[f] = saved[f];
                    }
                }
                restored.value = true;
            }
        } catch {
            // Speicher gesperrt (privates Fenster): ohne Entwurf weiter
        }
    }

    function write(): void {
        try {
            const values = Object.fromEntries(
                fields.map((f) => [f, form[f as keyof typeof form] ?? '']),
            );
            if (Object.values(values).every((v) => String(v).trim() === '')) {
                window.localStorage.removeItem(storageKey());
            } else {
                window.localStorage.setItem(
                    storageKey(),
                    JSON.stringify(values),
                );
            }
        } catch {
            // Speicher voll oder gesperrt: ohne Entwurf weiter
        }
    }

    read();
    watch(
        () => fields.map((f) => form[f as keyof typeof form]),
        () => {
            clearTimeout(timer);
            timer = setTimeout(write, 400);
        },
    );
    onBeforeUnmount(() => {
        if (timer) {
            clearTimeout(timer);
            write();
        }
    });

    return {
        restored,
        clear(): void {
            clearTimeout(timer);
            timer = undefined;
            restored.value = false;
            try {
                window.localStorage.removeItem(storageKey());
            } catch {
                // nichts zu tun
            }
        },
        /** Für einen anderen Gegenstand (z. B. eine andere Antwort) den passenden Entwurf laden. */
        reload: read,
    };
}
