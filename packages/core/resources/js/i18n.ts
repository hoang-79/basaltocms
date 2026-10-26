import type { App } from 'vue';
import { shallowRef } from 'vue';

/**
 * Übersetzung der Oberfläche (N-01): Deutsch ist die Quellsprache — der deutsche Text ist zugleich der Schlüssel, wie
 * bei den JSON-Übersetzungen von Laravel (`__('…')` auf dem Server liest dieselben Dateien). Englisch, Italienisch
 * und Französisch liegen in `packages/core/lang/<sprache>.json` und `modules/<modul>/lang/<sprache>.json` und werden je
 * Sprache als eigener Teil des Builds nachgeladen. Platzhalter wie in Laravel (`:name`), Mehrzahl mit «|».
 */
export type Messages = Record<string, string>;
export type Replacements = Record<string, string | number>;

export const LOCALES = ['de', 'en', 'it', 'fr'] as const;

const files = import.meta.glob<{ default: Messages }>([
    '../../lang/*.json',
    '../../../../modules/*/lang/*.json',
]);

const messages = shallowRef<Messages>({});
const current = shallowRef<string>('de');
const cache = new Map<string, Promise<Messages>>();

/** Die Dateien einer Sprache aus Kern und Modulen, zusammengelegt (Module nach dem Kern). */
function load(locale: string): Promise<Messages> {
    let promise = cache.get(locale);
    if (!promise) {
        const loaders = Object.entries(files)
            .filter(([path]) => path.endsWith(`/${locale}.json`))
            .sort(
                ([a], [b]) =>
                    Number(a.includes('/modules/')) -
                    Number(b.includes('/modules/')),
            )
            .map(([, loader]) => loader());
        promise = Promise.all(loaders).then(
            (parts) =>
                Object.assign(
                    {},
                    ...parts.map((part) => part.default),
                ) as Messages,
        );
        cache.set(locale, promise);
    }

    return promise;
}

/** Stellt die Sprache um; Deutsch braucht keine Datei. Unbekannte Sprachen fallen auf Deutsch. */
export async function setLocale(locale: string): Promise<void> {
    const lang = (LOCALES as readonly string[]).includes(locale)
        ? locale
        : 'de';
    if (
        lang === current.value &&
        (lang === 'de' || Object.keys(messages.value).length > 0)
    ) {
        return;
    }
    messages.value = lang === 'de' ? {} : await load(lang);
    current.value = lang;
}

export function locale(): string {
    return current.value;
}

/** Ersetzt `:name`-Platzhalter — längere Namen zuerst, damit `:count` nicht `:co` trifft. */
export function replace(text: string, replacements: Replacements = {}): string {
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (out, key) => out.replaceAll(`:${key}`, String(replacements[key])),
            text,
        );
}

/** Übersetzt einen deutschen Text; fehlt die Übersetzung, bleibt der deutsche. */
export function t(key: string, replacements: Replacements = {}): string {
    return replace(messages.value[key] || key, replacements);
}

/** Einzahl und Mehrzahl: «Ein Entwurf|:count Entwürfe» — `count` steht auch als Platzhalter bereit. */
export function tc(
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    const forms = (messages.value[key] || key).split('|');
    const form = forms.length > 1 && count !== 1 ? forms[1] : forms[0];

    return replace(form, { count, ...replacements });
}

/** Für Vorlagen: `$t('…')` und `$tc('…', n)` in jeder Komponente. */
export const i18n = {
    install(app: App): void {
        app.config.globalProperties.$t = t;
        app.config.globalProperties.$tc = tc;
    },
};

declare module 'vue' {
    interface ComponentCustomProperties {
        $t: typeof t;
        $tc: typeof tc;
    }
}
