import { ref } from 'vue';
import type { Ref } from 'vue';
import { getJson } from '../http';
import type { SnippetOption } from './layoutEditor';

/**
 * Was der Block-Editor über «+ Baustein» und «+ Lava-Teil» anbietet (M-28) — einmal je Seite geladen und von allen
 * Blocklisten geteilt.
 */
export type ReferenceOptions = {
    snippets: SnippetOption[];
    parts: { name: string; kind: string }[];
    manage: string | null;
};

const options = ref<ReferenceOptions | null>(null);
let loading: Promise<void> | null = null;

/** Die Auswahl der Site; `base` ist die Adresse des Backends (`backend.content_url`). */
export function useReferenceOptions(base: string): {
    options: Ref<ReferenceOptions | null>;
    load: () => Promise<void>;
} {
    const load = (): Promise<void> => {
        loading ??= getJson<ReferenceOptions>(
            `${base.replace(/\/$/, '')}/bausteine/auswahl`,
        )
            .then((data) => {
                options.value = data;
            })
            .catch(() => {
                loading = null;
            });

        return loading;
    };

    return { options, load };
}

/** Nach dem Speichern eines Bausteins neu laden (andere Seite, neuer Stand). */
export function forgetReferenceOptions(): void {
    options.value = null;
    loading = null;
}
