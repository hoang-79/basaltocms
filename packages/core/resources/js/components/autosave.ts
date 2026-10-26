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

export type AutosaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'error';

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

function now(): string {
    return new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date());
}

type Options = {
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
};

export function useAutosave<T extends object>(
    form: InertiaForm<T>,
    options: Options,
) {
    const delay = options.delay ?? 700;
    const savedFields = ref<string[]>([]);
    const status = ref<AutosaveStatus>('idle');
    let lastSaved = JSON.parse(JSON.stringify(form.data())) as T;
    let lastSavedJson = JSON.stringify(lastSaved);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let clearTimer: ReturnType<typeof setTimeout> | undefined;
    let queued = false;

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
                JSON.stringify(form.data()) === lastSavedJson
                    ? 'idle'
                    : 'pending';

            return Promise.resolve(status.value === 'idle');
        }
        if (form.processing) {
            queued = true;

            return new Promise((resolve) => waiters.push(resolve));
        }
        const sending = JSON.parse(JSON.stringify(form.data())) as T;
        const json = JSON.stringify(sending);
        if (json === lastSavedJson) {
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
        form.submit(options.method ?? 'put', url, {
            preserveScroll: true,
            preserveState: true,
            async: true,
            only: options.only ?? ['errors'],
            onSuccess: () => {
                lastSaved = sending;
                lastSavedJson = json;
                form.defaults(sending as Partial<T>);
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
                if (queued || JSON.stringify(form.data()) !== json) {
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
    }

    watch(
        () => JSON.stringify(form.data()),
        (json) => {
            if (json !== lastSavedJson) {
                schedule();
            }
        },
    );

    // Wer wegnavigiert, verliert nichts: was noch wartet, geht vorher raus (async — die Navigation bricht es nicht ab)
    const offBefore = router.on('before', (event) => {
        const visit = event.detail.visit as { async?: boolean };
        if (timer && !visit.async) {
            void flush();
        }
    });
    const onUnload = (event: BeforeUnloadEvent): void => {
        if (timer || form.processing) {
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
