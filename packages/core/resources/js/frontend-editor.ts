import { createApp, reactive } from 'vue';
import Bar from './frontend-editor/Bar.vue';
import { enableFields, markInvalid } from './frontend-editor/fields';
import { post, save } from './frontend-editor/http';
import { createSaveQueue } from './frontend-editor/saveQueue';
import { fieldMap, readConfig } from './frontend-editor/state';
import type { IslandState } from './frontend-editor/state';
import { BAR_CSS, FIELD_CSS } from './frontend-editor/styles';
import { setLocale, t } from './i18n';

/**
 * Frontend-Editor (M-19) — die Vue-Insel auf der Live-Seite. Eigenes kleines Bundle des Kerns; die Seite liefert es
 * nur an angemeldete Personen mit Recht aus (`FrontendEditor::inject`). Gäste bekommen weder Skript noch Attribut.
 */
async function start(): Promise<void> {
    const config = readConfig(document);
    if (!config) {
        return;
    }
    await setLocale(config.ui_locale ?? 'de');
    const fields = fieldMap(config);

    const state = reactive<IslandState>({
        locale: config.locale,
        editing: config.editing,
        live: config.live,
        version: config.version,
        fieldCount: 0,
        message: '',
        tone: 'info',
        busy: false,
        backendUrl: config.urls.backend,
    });

    // Die Leiste im Shadow DOM; der Wirt ist zugleich Platzhalter am Ende der Seite, damit sie nichts verdeckt
    const host = document.createElement('div');
    host.id = 'basalto-frontend-editor-host';
    host.style.cssText =
        'display:block;margin:0;padding:0;border:0;grid-column:1/-1;height:0';
    document.body.appendChild(host);
    const root = host.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = BAR_CSS;
    root.appendChild(style);
    const mount = document.createElement('div');
    root.appendChild(mount);

    let hosts: HTMLElement[] = [];
    let leaving = false;
    const queue = createSaveQueue({
        base: { id: config.version.id, stamp: config.version.stamp },
        send: async (base, changes) => {
            const result = await save(config.urls.save, base, changes);
            if (result.ok && result.version) {
                state.version = result.version;
            }

            return result;
        },
        onState: (status, message, invalid) => {
            if (status === 'saving') {
                state.message = t('Speichert …');
                state.tone = 'info';
            } else if (status === 'saved') {
                state.message = message;
                state.tone = 'saved';
                markInvalid(hosts, []);
            } else if (status === 'error' || status === 'conflict') {
                state.message = t('Nicht gespeichert: :message', { message });
                state.tone = 'error';
                markInvalid(hosts, invalid);
            }
        },
    });

    const toggle = async (): Promise<void> => {
        state.busy = true;
        if (state.editing) {
            await queue.flush();
            if (
                queue.hasUnsaved() &&
                !window.confirm(
                    t(
                        'Nicht alle Änderungen sind gespeichert. Trotzdem beenden?',
                    ),
                )
            ) {
                state.busy = false;

                return;
            }
            leaving = true;
        }
        const response = await post(config.urls.mode, {
            on: !state.editing,
        }).catch(() => null);
        if (!response?.ok) {
            state.message = t(
                'Der Modus liess sich nicht umschalten — bitte neu laden.',
            );
            state.tone = 'error';
            state.busy = false;

            return;
        }
        window.location.reload();
    };

    createApp(Bar, { state, onToggle: () => void toggle() }).mount(mount);
    const bar = root.querySelector<HTMLElement>('.bar');
    const resize = (): void => {
        host.style.height = `${(bar?.offsetHeight ?? 0) + 24}px`;
    };
    if (bar) {
        new ResizeObserver(resize).observe(bar);
    }
    resize();

    if (!config.editing) {
        return;
    }
    const css = document.createElement('style');
    css.id = 'basalto-frontend-editor-fields';
    css.textContent = FIELD_CSS;
    document.head.appendChild(css);
    hosts = enableFields(
        document,
        fields,
        (field, value) => queue.set(field, value),
        () => void queue.flush(),
    );
    state.fieldCount = hosts.length;
    window.addEventListener('beforeunload', (event) => {
        if (!leaving && queue.hasUnsaved()) {
            event.preventDefault();
        }
    });
}

void start();
