<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { getJson } from '../http';
import { t } from '../i18n';
import type { AliasResult } from '../types-saas';
import { controlClasses } from './control';
import Field from './Field.vue';

/**
 * TB 12 Scheibe 1 (G-11, Mockup 22): Alias einer SaaS-Site mit der Alias-Domain dahinter und Prüfung beim Tippen
 * («ist frei ✓», «schon vergeben — Vorschlag: …»). Die Prüfung ist eine Hilfe: Ohne sie prüft der Server beim Absenden
 * dasselbe. Das Ergebnis wird über `aria-live` angesagt.
 */
const props = withDefaults(
    defineProps<{
        checkUrl: string;
        domain: string;
        siteName?: string;
        error?: string;
        /** TB 12 Scheibe 3: der heutige Alias (Alias ändern) — dafür keine Prüfung «schon vergeben» */
        current?: string;
    }>(),
    { siteName: '', error: '', current: '' },
);
const model = defineModel<string>({ required: true });

const result = ref<AliasResult | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

async function check(value: string): Promise<void> {
    const mine = ++sequence;
    if (
        value.trim() === '' ||
        (props.current !== '' && value.trim() === props.current)
    ) {
        result.value = null;

        return;
    }
    try {
        const params = new URLSearchParams({
            name: value,
            site: props.siteName,
        });
        const answer = await getJson<AliasResult>(
            `${props.checkUrl}?${params.toString()}`,
        );
        if (mine === sequence) {
            result.value = answer;
        }
    } catch {
        // Ohne Antwort keine Anzeige — der Server prüft beim Absenden
        result.value = null;
    }
}

watch(
    model,
    (value) => {
        clearTimeout(timer);
        timer = setTimeout(() => void check(value), 300);
    },
    { immediate: true },
);
// TB 12 Scheibe 3: Nach «Alias ändern» ist der neue Alias der heutige — keine Meldung «ist frei» mehr stehen lassen
watch(
    () => props.current,
    (current) => {
        if (current !== '' && model.value.trim() === current) {
            result.value = null;
        }
    },
);
onBeforeUnmount(() => clearTimeout(timer));

function take(suggestion: string): void {
    model.value = suggestion;
}

function onInput(event: Event): void {
    model.value = (event.target as HTMLInputElement).value
        .toLowerCase()
        .replace(/\s+/g, '-');
}
</script>

<template>
    <Field :label="t('Alias')" :error="error">
        <template #default="{ id, invalid, describedby }">
            <div class="flex items-stretch">
                <input
                    :id="id"
                    type="text"
                    :value="model"
                    inputmode="url"
                    autocomplete="off"
                    spellcheck="false"
                    maxlength="40"
                    required
                    :class="[
                        controlClasses({ error: invalid, mono: true }),
                        'rounded-r-none',
                    ]"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedby"
                    @input="onInput"
                />
                <span
                    class="border-ash-dark flex items-center rounded-r-md border border-l-0 bg-paper px-2.5 font-mono text-[12px] whitespace-nowrap text-basalt-light"
                    >.{{ domain }}</span
                >
            </div>
        </template>
        <template #help>
            <span aria-live="polite" class="block">
                <span
                    v-if="result && !error"
                    class="font-semibold"
                    :class="
                        result.state === 'free' ? 'text-success' : 'text-danger'
                    "
                    >{{ result.message }}</span
                >
                <button
                    v-if="
                        result && result.suggestion && result.state !== 'free'
                    "
                    type="button"
                    class="ml-1.5 text-lava-dark underline hover:text-basalt"
                    @click="take(result.suggestion)"
                >
                    {{ t(':alias übernehmen', { alias: result.suggestion }) }}
                </button>
            </span>
            <span class="block">{{
                t(
                    '3–40 Zeichen, a–z, 0–9 und Bindestrich · später änderbar, der alte Alias leitet 30 Tage um',
                )
            }}</span>
        </template>
    </Field>
</template>
