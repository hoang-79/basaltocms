import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, defineComponent, h, reactive } from 'vue';
import type { InertiaForm } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', () => ({ router: { on: () => () => undefined } }));

import { AUTOSAVE_HEADER, expandPath, useAutosave, useDraft } from './autosave';

type Data = { title: string; slug: string; note: string };
type Sent = { data: Data; headers: Record<string, string> };

/** Ein Formular wie `useForm` — nur, was `useAutosave` braucht; `respond` entscheidet über Erfolg oder Fehler. */
function fakeForm(
    initial: Data,
    respond: (data: Data) => Record<string, string> | null,
) {
    const sent: Sent[] = [];
    let shape: ((data: Data) => object) | null = null;
    const form = reactive({
        ...initial,
        processing: false,
        errors: {} as Record<string, string>,
        data(): Data {
            return { title: form.title, slug: form.slug, note: form.note };
        },
        transform(callback: (data: Data) => object) {
            shape = callback;

            return form;
        },
        defaults() {
            return form;
        },
        setError(field: string, message: string) {
            form.errors[field] = message;
        },
        clearErrors() {
            form.errors = {};

            return form;
        },
        submit(
            _method: string,
            _url: string,
            options: Record<string, (arg?: unknown) => void> & {
                headers: Record<string, string>;
            },
        ) {
            const data = (shape ? shape(form.data()) : form.data()) as Data;
            sent.push({ data, headers: options.headers });
            const errors = respond(data);
            if (errors) {
                form.errors = errors;
                options.onError?.(errors);
            } else {
                form.errors = {};
                options.onSuccess?.();
            }
            options.onFinish?.();
        },
    });

    return { form, sent };
}

function setup(
    respond: (data: Data) => Record<string, string> | null = () => null,
) {
    const { form, sent } = fakeForm(
        { title: 'Titel', slug: 'alt', note: '' },
        respond,
    );
    let save!: ReturnType<typeof useAutosave<Data>>;
    const root = document.createElement('div');
    createApp(
        defineComponent({
            setup() {
                save = useAutosave(form as unknown as InertiaForm<Data>, {
                    url: '/x',
                    onLeave: ['slug'],
                });

                return () => h('div');
            },
        }),
    ).mount(root);

    return { form, sent, save };
}

describe('Autosave mit Speicherarten (CR-013)', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('Text speichert nach der Pause, mit der Kopfzeile für das Protokoll', async () => {
        const { form, sent } = setup();
        form.title = 'Neuer Titel';
        await vi.advanceTimersByTimeAsync(699);
        expect(sent).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(sent).toHaveLength(1);
        expect(sent[0]?.data.title).toBe('Neuer Titel');
        expect(sent[0]?.headers[AUTOSAVE_HEADER]).toBe('1');
    });

    it('ein Feld «beim Verlassen» wartet auf Enter oder Verlassen', async () => {
        const { form, sent, save } = setup();
        form.slug = 'neu';
        await vi.advanceTimersByTimeAsync(2000);
        expect(sent).toHaveLength(0);
        expect(save.status.value).toBe('waiting');
        expect(save.waiting('slug')).toBe(true);

        // Eine andere Änderung speichert — die Kennung geht noch mit dem alten Wert
        form.title = 'Anders';
        await vi.advanceTimersByTimeAsync(700);
        expect(sent.at(-1)?.data).toMatchObject({
            title: 'Anders',
            slug: 'alt',
        });

        save.leave('slug').onKeydown(
            new KeyboardEvent('keydown', { key: 'Enter' }),
        );
        await vi.advanceTimersByTimeAsync(0);
        expect(sent.at(-1)?.data).toMatchObject({
            title: 'Anders',
            slug: 'neu',
        });
        expect(save.waiting('slug')).toBe(false);
    });

    it('Esc nimmt die Änderung zurück, Verlassen speichert', async () => {
        const { form, sent, save } = setup();
        form.slug = 'tippfehler';
        save.leave('slug').onKeydown(
            new KeyboardEvent('keydown', { key: 'Escape' }),
        );
        expect(form.slug).toBe('alt');
        form.slug = 'richtig';
        save.leave('slug').onFocusout();
        await vi.advanceTimersByTimeAsync(0);
        expect(sent).toHaveLength(1);
        expect(sent[0]?.data.slug).toBe('richtig');
    });

    it('ein abgelehntes Feld blockiert die übrigen nicht', async () => {
        const { form, sent } = setup((data) =>
            data.title === '' ? { title: 'Bitte einen Titel angeben.' } : null,
        );
        form.title = '';
        await vi.advanceTimersByTimeAsync(700);
        expect(form.errors.title).toBe('Bitte einen Titel angeben.');

        // Die Notiz speichert trotzdem — mit dem zuletzt gespeicherten Titel; der Fehler bleibt am Feld
        form.note = 'Eine Notiz';
        await vi.advanceTimersByTimeAsync(700);
        expect(sent.at(-1)?.data).toMatchObject({
            title: 'Titel',
            note: 'Eine Notiz',
        });
        expect(form.errors.title).toBe('Bitte einen Titel angeben.');

        // Neu bearbeitet: der Titel geht wieder mit
        form.title = 'Gültig';
        await vi.advanceTimersByTimeAsync(700);
        expect(sent.at(-1)?.data).toMatchObject({
            title: 'Gültig',
            note: 'Eine Notiz',
        });
        expect(form.errors.title).toBeUndefined();
    });

    it('Muster mit «*» treffen jede Zeile einer Liste', () => {
        expect(
            expandPath('fields.*.key', {
                fields: [{ key: 'a' }, { key: 'b' }],
            }),
        ).toEqual(['fields.0.key', 'fields.1.key']);
        expect(expandPath('slug', { slug: 'x' })).toEqual(['slug']);
        expect(expandPath('fehlt.*', { slug: 'x' })).toEqual([]);
    });

    it('ein Entwurf im Browser übersteht das Neuladen und fällt nach dem Senden weg', async () => {
        window.localStorage.clear();
        const mountDraft = () => {
            const form = reactive({ body: '' }) as unknown as InertiaForm<{
                body: string;
            }>;
            let draft!: ReturnType<typeof useDraft<{ body: string }>>;
            const app = createApp(
                defineComponent({
                    setup() {
                        draft = useDraft(form, () => 'test.notiz', ['body']);

                        return () => h('div');
                    },
                }),
            );
            app.mount(document.createElement('div'));

            return { form, draft, app };
        };
        const first = mountDraft();
        (first.form as unknown as { body: string }).body = 'Halb geschrieben';
        await vi.advanceTimersByTimeAsync(400);
        first.app.unmount();

        const second = mountDraft();
        expect((second.form as unknown as { body: string }).body).toBe(
            'Halb geschrieben',
        );
        expect(second.draft.restored.value).toBe(true);
        second.draft.clear();
        expect(
            window.localStorage.getItem('basalto.entwurf.test.notiz'),
        ).toBeNull();
    });
});
