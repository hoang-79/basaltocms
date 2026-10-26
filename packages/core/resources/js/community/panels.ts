import type { Editor } from '@tiptap/core';
import { enhanceCode } from './code';
import { icon } from './icons';
import { type Say, emojiGrid } from './tools';
import { escapeHtml } from './toolbar';

/**
 * Reiter unter dem Editor (TB 49, wie WoltLab): Smileys, Dateianhänge (Hochladen, Regeln, Liste), Einstellungen und
 * Umfrage (Felder, die der Server mit `data-insel-reiter` markiert — ohne Skript stehen sie im Formular) und rechts die
 * Vorschau. Ein Klick auf den offenen Reiter schliesst ihn wieder.
 */
export interface PanelOptions {
    editor: Editor;
    say: Say;
    shell: HTMLElement;
    form: HTMLFormElement | null;
    /** Knopf «Hochladen» und Regeln der Anhänge — nur mit Anhängen (die Liste bleibt unter dem Text sichtbar). */
    upload?: { pick: () => void; rules: string };
    previewUrl?: string;
    token: () => string;
    lang: string;
}

interface Tab {
    key: string;
    icon: string;
    label: string;
    content: () => HTMLElement;
    onOpen?: (panel: HTMLElement) => void;
    end?: boolean;
}

export function buildPanels(options: PanelOptions): void {
    const { editor, say, shell, form } = options;
    const tabs: Tab[] = [
        {
            key: 'smileys',
            icon: 'smile',
            label: say('smileys', 'Smileys'),
            content: () =>
                emojiGrid(
                    say,
                    (emoji) =>
                        editor.chain().focus().insertContent(emoji).run(),
                    12,
                    false,
                ),
        },
    ];
    if (options.upload) {
        const { pick, rules } = options.upload;
        tabs.push({
            key: 'anhaenge',
            icon: 'paperclip',
            label: say('attachments', 'Dateianhänge'),
            content: () => {
                const wrapper = document.createElement('div');
                wrapper.className = 'insel-reiter__anhaenge';
                const button = document.createElement('button');
                button.type = 'button';
                button.className =
                    'insel-menue__knopf insel-menue__knopf--haupt';
                button.innerHTML = `${icon('upload', 16)} ${escapeHtml(say('upload', 'Hochladen'))}`;
                button.addEventListener('click', pick);
                const hint = document.createElement('p');
                hint.className = 'insel-reiter__hinweis';
                hint.textContent = rules;
                wrapper.append(button);
                if (rules !== '') {
                    wrapper.append(hint);
                }

                return wrapper;
            },
        });
    }
    for (const [key, iconName, label] of [
        ['einstellungen', 'settings', say('settings', 'Einstellungen')],
        ['umfrage', 'poll', say('poll', 'Umfrage')],
    ] as const) {
        const moved = form
            ? Array.from(
                  form.querySelectorAll<HTMLElement>(
                      `[data-insel-reiter="${key}"]`,
                  ),
              )
            : [];
        if (moved.length > 0) {
            tabs.push({
                key,
                icon: iconName,
                label,
                content: () => {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'insel-reiter__felder';
                    moved.forEach((element) => {
                        if (element instanceof HTMLDetailsElement) {
                            element.open = true;
                        }
                        wrapper.append(element);
                    });

                    return wrapper;
                },
            });
        }
    }
    if (options.previewUrl) {
        const url = options.previewUrl;
        tabs.push({
            key: 'vorschau',
            icon: 'eye',
            label: say('preview', 'Vorschau'),
            end: true,
            content: () => {
                const wrapper = document.createElement('div');
                wrapper.className = 'insel-reiter__vorschau forum-post__text';
                wrapper.setAttribute('aria-live', 'polite');

                return wrapper;
            },
            onOpen: (panel) => {
                const target = panel.firstElementChild as HTMLElement;
                target.textContent = say(
                    'preview_loading',
                    'Vorschau wird erstellt …',
                );
                const data = new FormData();
                data.append('doc', JSON.stringify(editor.getJSON()));
                data.append('_sprache', options.lang);
                void fetch(url, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': options.token(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((response) =>
                        response.ok
                            ? (response.json() as Promise<{ html: string }>)
                            : Promise.reject(
                                  new Error(String(response.status)),
                              ),
                    )
                    .then(({ html }) => {
                        target.innerHTML =
                            html === ''
                                ? `<p class="insel-reiter__hinweis">${say('preview_empty', 'Noch nichts geschrieben.')}</p>`
                                : html;
                        void enhanceCode(target);
                    })
                    .catch(() => {
                        target.textContent = say(
                            'preview_failed',
                            'Die Vorschau liess sich nicht laden.',
                        );
                    });
            },
        });
    }

    const bar = document.createElement('div');
    bar.className = 'insel-reiter';
    bar.setAttribute('role', 'tablist');
    bar.setAttribute('aria-label', say('extras', 'Zusätze'));
    const panels = document.createElement('div');
    panels.className = 'insel-reiter__tafeln';
    const buttons: HTMLButtonElement[] = [];
    let openKey: string | null = null;

    tabs.forEach((tab) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `insel-reiter__knopf${tab.end ? ' insel-reiter__knopf--ende' : ''}`;
        button.id = `${shell.id}-reiter-${tab.key}`;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-selected', 'false');
        button.setAttribute('aria-controls', `${shell.id}-tafel-${tab.key}`);
        button.tabIndex = buttons.length === 0 ? 0 : -1;
        button.innerHTML = `${icon(tab.icon, 16)}<span>${escapeHtml(tab.label)}</span>`;
        const panel = document.createElement('div');
        panel.className = 'insel-reiter__tafel';
        panel.id = `${shell.id}-tafel-${tab.key}`;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', button.id);
        panel.hidden = true;
        let built = false;
        button.addEventListener('click', () => {
            const opening = openKey !== tab.key;
            buttons.forEach((other) =>
                other.setAttribute('aria-selected', 'false'),
            );
            panels
                .querySelectorAll<HTMLElement>('.insel-reiter__tafel')
                .forEach((other) => (other.hidden = true));
            openKey = opening ? tab.key : null;
            if (!opening) {
                return;
            }
            if (!built) {
                panel.append(tab.content());
                built = true;
            }
            button.setAttribute('aria-selected', 'true');
            panel.hidden = false;
            tab.onOpen?.(panel);
        });
        buttons.push(button);
        bar.append(button);
        panels.append(panel);
        // Felder für Einstellungen und Umfrage gleich umziehen, damit sie im Formular bleiben
        if (tab.key === 'einstellungen' || tab.key === 'umfrage') {
            panel.append(tab.content());
            built = true;
        }
    });
    bar.addEventListener('keydown', (event) => {
        const index = buttons.indexOf(
            document.activeElement as HTMLButtonElement,
        );
        const moves: Record<string, number> = {
            ArrowRight: index + 1,
            ArrowLeft: index - 1,
            Home: 0,
            End: buttons.length - 1,
        };
        if (index >= 0 && event.key in moves) {
            event.preventDefault();
            const next =
                buttons[(moves[event.key] + buttons.length) % buttons.length];
            buttons.forEach((button) => (button.tabIndex = -1));
            next.tabIndex = 0;
            next.focus();
        }
    });
    // Neuer Text macht eine offene Vorschau veraltet — sie schliesst sich
    editor.on('update', () => {
        if (openKey === 'vorschau') {
            buttons.find((button) => button.id.endsWith('-vorschau'))?.click();
        }
    });
    shell.append(bar, panels);
}
