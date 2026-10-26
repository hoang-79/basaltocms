import { usePage } from '@inertiajs/vue3';
import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { postJson, requestError } from '@core/http';
import type { BackendProps } from '@core/types';
import type { Change } from '@core/types-workflow';

/** Die Funktionen des KI-Moduls (M-24). */
export type AiFeature = 'improve' | 'alt_text' | 'translate' | 'seo';

/** Was das Modul mit dem Backend teilt (Backend::share('ai')) — nie der Schlüssel. */
export type AiShared = {
    ready: boolean;
    notice: string | null;
    features: Record<AiFeature, boolean>;
    provider: string;
    privacy: string;
    urls: {
        improve: string;
        translate: string;
        seo: string;
        alt_text: string;
        settings: string | null;
    };
};

/** Jede Antwort trägt die Nutzung und die Adresse, unter der «Übernehmen» gemeldet wird. */
export type AiAnswer = { usage: number; applied_url: string };

export type FieldSuggestion = AiAnswer & {
    kind: 'text' | 'textarea' | 'richtext';
    value: unknown;
    before: string;
    after: string;
};

export type SeoSuggestion = AiAnswer & { title: string; description: string };

export type AltSuggestion = AiAnswer & { alt: Record<string, string> };

/** Die geteilten Props des Moduls — `null`, wenn das Modul auf der Site aus ist (dann zeigt sich nichts). */
export function useAi(): ComputedRef<AiShared | null> {
    const page = usePage<BackendProps>();

    return computed(
        () =>
            (page.props.backend.extensions?.ai as
                | AiShared
                | null
                | undefined) ?? null,
    );
}

/**
 * Ein Vorschlag auf Klick: lädt, merkt sich Ergebnis oder Fehler. Gespeichert wird dabei nichts — erst «Übernehmen»
 * legt den Wert ins Feld, und das Autosave der Seite schreibt ihn in den Entwurf.
 */
export function useSuggestion<T extends AiAnswer>(): {
    loading: Ref<boolean>;
    result: Ref<T | null>;
    error: Ref<string>;
    request: (url: string, data: Record<string, unknown>) => Promise<void>;
    applied: (data?: Record<string, unknown>) => void;
    reset: () => void;
} {
    const loading = ref(false);
    const result = ref<T | null>(null) as Ref<T | null>;
    const error = ref('');

    async function request(
        url: string,
        data: Record<string, unknown>,
    ): Promise<void> {
        loading.value = true;
        error.value = '';
        result.value = null;
        try {
            result.value = await postJson<T>(url, data);
        } catch (e) {
            error.value = requestError(e);
        } finally {
            loading.value = false;
        }
    }

    /** «Übernehmen» ins Protokoll — ohne Inhalt; ein Fehler hier hält die Übernahme nicht auf. */
    function applied(data: Record<string, unknown> = {}): void {
        if (result.value) {
            void postJson(result.value.applied_url, data).catch(() => {});
        }
    }

    function reset(): void {
        result.value = null;
        error.value = '';
    }

    return { loading, result, error, request, applied, reset };
}

/** Hat ein Feldwert Text — Klartext oder ein Fliesstext-Dokument mit mindestens einem Textknoten? Leere Felder bekommen keinen Knopf. */
export function hasText(value: unknown): boolean {
    if (typeof value === 'string') {
        return value.trim() !== '';
    }
    if (value === null || typeof value !== 'object') {
        return false;
    }
    const node = value as { type?: unknown; text?: unknown; content?: unknown };
    if (node.type === 'text' && typeof node.text === 'string') {
        return node.text.trim() !== '';
    }

    return Array.isArray(node.content) && node.content.some(hasText);
}

/** Eine Zeile für den Vergleich: «neu», wenn bisher nichts da war, sonst «geändert». */
export function change(
    path: string,
    label: string,
    before: string,
    after: string,
): Change {
    return before.trim() === ''
        ? { path, label, kind: 'added', old: null, new: after }
        : { path, label, kind: 'changed', old: before, new: after };
}
