<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import { t, tc } from '../i18n';
import { controlClasses } from './control';
import CountrySelect from './CountrySelect.vue';
import {
    groupDigits,
    nationalInput,
    parsePhone,
    type PhoneCountry,
} from './phone';

/**
 * Telefonnummer mit fester Vorwahl (Profil, K-03): links die Flagge (Klick öffnet die Länderwahl mit Suche), dann die
 * Vorwahl des Landes als fester Text, dann die nationale Nummer — beim Tippen gruppiert und auf die Länge des Landes
 * gekürzt. «+49 …» oder «0049 …» getippt oder eingefügt stellt das Land selbst um. Das Modell ist die ganze Nummer
 * («+41 79 123 45 67», leer ohne Ziffern), `v-model:country` der ISO-Code. Fehlen noch Ziffern, sagt ein Hinweis
 * unter dem Feld, wie viele.
 */
const props = withDefaults(
    defineProps<{
        countries: PhoneCountry[];
        id?: string;
        invalid?: boolean;
    }>(),
    { id: undefined, invalid: false },
);
const model = defineModel<string>({ default: '' });
const country = defineModel<string>('country', { default: 'CH' });

/** Was im Feld steht: die gruppierten nationalen Ziffern (oder eine angefangene Vorwahl wie «+4»). */
const display = ref('');
const national = ref('');
/** Das Land, für das das Feld gerade gilt — so unterscheidet der Watcher die Flagge vom Umstellen per «+». */
let shownFor = '';
/** Der zuletzt gesendete Modellwert: kommt er als Prop zurück, bleibt das Feld, wie es ist. */
let emitted: string | null = null;

const uid = useId();
const hintId = computed(() => `${props.id ?? `phone-${uid}`}-digits`);
const chosen = computed(() =>
    props.countries.find((c) => c.code === country.value),
);
const missing = computed(() => {
    const c = chosen.value;
    const length = national.value.length;

    return c && length > 0 && length < c.min ? c.min - length : 0;
});
const hint = computed(() =>
    tc(
        'Es fehlt noch :count Ziffer für :country.|Es fehlen noch :count Ziffern für :country.',
        missing.value,
        { country: chosen.value?.name ?? '' },
    ),
);
const dial = computed(() => chosen.value?.dial ?? '');

/** Feld und Modell setzen: leer ohne Ziffern, sonst «+Vorwahl gruppiert». */
function show(
    c: PhoneCountry | undefined,
    digits: string,
    shown: string,
): void {
    national.value = digits;
    display.value = shown;
    shownFor = c?.code ?? country.value;
    const value = digits === '' ? '' : c ? `+${c.dial} ${shown}` : digits;
    emitted = value;
    model.value = value;
}

/* Anfangswert und Änderungen von aussen (etwa die Antwort des Servers): Land und Ziffern aus der ganzen Nummer */
watch(
    model,
    (value) => {
        if (value === emitted) {
            return;
        }
        emitted = null;
        const parsed =
            value.trim() === ''
                ? null
                : parsePhone(value, chosen.value, props.countries);
        if (!parsed) {
            national.value = '';
            display.value = value;
            shownFor = country.value;

            return;
        }
        national.value = parsed.national;
        display.value = groupDigits(parsed.national, parsed.country.groups);
        shownFor = parsed.country.code;
        if (country.value !== parsed.country.code) {
            country.value = parsed.country.code;
        }
    },
    { immediate: true },
);

/* Neues Land über die Flagge: die Ziffern bleiben, gekürzt und gruppiert für das neue Land */
watch(country, (code) => {
    if (code === shownFor) {
        return;
    }
    const c = props.countries.find((x) => x.code === code);
    if (!c) {
        show(undefined, national.value, national.value);

        return;
    }
    const digits = (
        !c.keep_zero && national.value.startsWith('0')
            ? national.value.slice(1)
            : national.value
    ).slice(0, c.max);
    show(c, digits, groupDigits(digits, c.groups));
});

/**
 * Der Anfang einer Landesvorwahl, die noch nicht vollständig ist («+», «+4», «00», «004», eine einzelne 0 vor der
 * zweiten): bleibt stehen, wie getippt — sonst verschwände das «+» schon beim ersten Zeichen.
 */
function startsDial(raw: string): boolean {
    const head = raw.replace(/[\s\-./()]/g, '');
    if (head === '0') {
        return !(chosen.value?.keep_zero ?? false);
    }
    const digits = /^(?:\+|00)([0-9]*)$/.exec(head)?.[1];
    if (digits === undefined) {
        return false;
    }

    return (
        props.countries.some((c) => c.dial.startsWith(digits)) &&
        !props.countries.some((c) => c.dial !== '' && digits.startsWith(c.dial))
    );
}

/** Die Stelle im gruppierten Text nach der n-ten Ziffer. */
function caretAfter(shown: string, digits: number): number {
    let seen = 0;
    for (let i = 0; i < shown.length && digits > 0; i++) {
        if (shown[i] >= '0' && shown[i] <= '9' && ++seen === digits) {
            return i + 1;
        }
    }

    return digits > 0 ? shown.length : 0;
}

function onInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const raw = el.value;
    if (startsDial(raw)) {
        show(chosen.value, '', raw);

        return;
    }
    const result = nationalInput(raw, chosen.value, props.countries);
    // Die Schreibmarke bleibt hinter derselben Ziffer, auch wenn Leerzeichen dazukommen oder wegfallen
    const before = Math.min(
        result.national.length,
        nationalInput(
            raw.slice(0, el.selectionStart ?? raw.length),
            result.country,
            props.countries,
        ).national.length,
    );
    show(result.country, result.national, result.grouped);
    if (result.country && result.country.code !== country.value) {
        country.value = result.country.code;
    }
    // Direkt setzen: Bleibt der Text gleich (Buchstabe getippt), aktualisiert Vue das Feld nicht
    el.value = result.grouped;
    const caret =
        before >= result.national.length
            ? result.grouped.length
            : caretAfter(result.grouped, before);
    void nextTick(() => {
        if (document.activeElement === el) {
            el.setSelectionRange(caret, caret);
        }
    });
}
</script>

<template>
    <div class="w-full min-w-0">
        <div class="flex items-stretch gap-2">
            <CountrySelect
                v-model="country"
                compact
                :countries="countries"
                :label="t('Land der Telefonnummer')"
            />
            <div class="flex min-w-0 flex-1 items-stretch">
                <span
                    class="flex flex-none items-center rounded-l-md border border-r-0 bg-paper px-2.5 text-[13px] whitespace-nowrap text-basalt-light tabular-nums select-none"
                    :class="invalid ? 'border-danger' : 'border-ash-dark'"
                    :title="
                        t('Vorwahl :country — ändern über die Flagge', {
                            country: chosen?.name ?? '',
                        })
                    "
                    >+{{ dial }}</span
                >
                <input
                    :id="id"
                    :value="display"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel-national"
                    :class="[
                        controlClasses({ error: invalid }),
                        'min-w-0 flex-1 rounded-l-none tabular-nums',
                    ]"
                    :aria-label="
                        t('Telefonnummer ohne Vorwahl +:dial', { dial })
                    "
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="missing > 0 ? hintId : undefined"
                    @input="onInput"
                />
            </div>
        </div>
        <p
            v-if="missing > 0"
            :id="hintId"
            class="mt-1 text-[11.5px] text-basalt-light"
        >
            {{ hint }}
        </p>
    </div>
</template>
