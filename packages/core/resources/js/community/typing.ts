import type { Editor } from '@tiptap/core';

/**
 * «Wer schreibt gerade» (TB 49), Seite der Schreibenden: Solange im Editor getippt wird, meldet die Insel das alle vier
 * Sekunden; nach sechs Sekunden Pause, beim Absenden und beim Verlassen der Seite meldet sie das Ende. Nur Eingaben mit
 * Fokus zählen — ein wiederhergestellter Entwurf nicht.
 */
export interface TypingConfig {
    url: string;
    key: string;
    token: string;
}

export function reportTyping(
    editor: Editor,
    config: TypingConfig,
    form: HTMLFormElement | null,
    csrf: () => string,
    now: () => number = () => Date.now(),
): () => void {
    let active = false;
    let lastSent = 0;
    let idle: ReturnType<typeof setTimeout> | undefined;
    const send = (aktiv: boolean, keepalive = false): void => {
        const data = new FormData();
        data.append('key', config.key);
        data.append('token', config.token);
        data.append('aktiv', aktiv ? '1' : '0');
        void fetch(config.url, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            keepalive,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).catch(() => undefined);
    };
    const stop = (keepalive = false): void => {
        clearTimeout(idle);
        if (active) {
            active = false;
            send(false, keepalive);
        }
    };
    const onUpdate = (): void => {
        if (!editor.isFocused) {
            return;
        }
        const time = now();
        if (!active || time - lastSent >= 4000) {
            active = true;
            lastSent = time;
            send(true);
        }
        clearTimeout(idle);
        idle = setTimeout(() => stop(), 6000);
    };
    editor.on('update', onUpdate);
    form?.addEventListener('submit', () => stop(true));
    window.addEventListener('pagehide', () => stop(true));

    return () => {
        editor.off('update', onUpdate);
        stop();
    };
}
