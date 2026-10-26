/**
 * Frontend-Editor (M-19): Änderungen sammeln und nach einer kurzen Pause speichern — wie der Autosave im Backend,
 * aber ohne Inertia. Je Feld zählt der letzte Wert; es läuft höchstens eine Anfrage, was währenddessen kommt, folgt
 * danach. Ein veralteter Stand (409) hält die Schlange an, damit nichts Fremdes überschrieben wird.
 */

export type Change = { field: string; value: unknown };

export type Base = { id: number; stamp: string };

export type SaveResult =
    | {
          ok: true;
          base: Base;
          message: string;
          version?: {
              id: number;
              number: number;
              status: string;
              status_label: string;
              stamp: string;
          };
      }
    | { ok: false; conflict: boolean; message: string; fields: string[] };

export type QueueState =
    | 'idle'
    | 'pending'
    | 'saving'
    | 'saved'
    | 'error'
    | 'conflict';

export type SaveQueue = {
    set(field: string, value: unknown): void;
    flush(): Promise<void>;
    state(): QueueState;
    hasUnsaved(): boolean;
};

export function createSaveQueue(options: {
    base: Base;
    send: (base: Base, changes: Change[]) => Promise<SaveResult>;
    onState: (state: QueueState, message: string, fields: string[]) => void;
    delay?: number;
}): SaveQueue {
    const delay = options.delay ?? 800;
    let base = options.base;
    let pending = new Map<string, unknown>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let running: Promise<void> | null = null;
    let current: QueueState = 'idle';

    const report = (
        state: QueueState,
        message = '',
        fields: string[] = [],
    ): void => {
        current = state;
        options.onState(state, message, fields);
    };

    const run = async (): Promise<void> => {
        if (current === 'conflict' || pending.size === 0) {
            return;
        }
        const changes = Array.from(pending, ([field, value]) => ({
            field,
            value,
        }));
        pending = new Map();
        report('saving');
        const result = await options.send(base, changes);
        if (result.ok) {
            base = result.base;
            report(pending.size > 0 ? 'pending' : 'saved', result.message);
        } else {
            // Nicht gespeichert: die Werte bleiben vorgemerkt (ausser ein neuerer kam schon) — die nächste Änderung versucht es wieder
            for (const change of changes) {
                if (!pending.has(change.field)) {
                    pending.set(change.field, change.value);
                }
            }
            report(
                result.conflict ? 'conflict' : 'error',
                result.message,
                result.fields,
            );
        }
    };

    const flush = async (): Promise<void> => {
        if (timer) {
            clearTimeout(timer);
            timer = undefined;
        }
        while (running) {
            await running;
        }
        if (pending.size === 0) {
            return;
        }
        running = run().finally(() => {
            running = null;
        });
        await running;
        if (pending.size > 0 && current !== 'conflict' && current !== 'error') {
            await flush();
        }
    };

    return {
        set(field: string, value: unknown): void {
            if (current === 'conflict') {
                return;
            }
            pending.set(field, value);
            report('pending');
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(() => void flush(), delay);
        },
        flush,
        state: () => current,
        hasUnsaved: () => pending.size > 0 || current === 'saving',
    };
}

/** Das XSRF-Token aus dem Cookie (Laravel legt es verschlüsselt und URL-kodiert ab) — wie der XHR-Client von Inertia. */
export function xsrfToken(cookies: string): string | null {
    for (const part of cookies.split(';')) {
        const [name, ...rest] = part.trim().split('=');
        if (name === 'XSRF-TOKEN') {
            return decodeURIComponent(rest.join('='));
        }
    }

    return null;
}
