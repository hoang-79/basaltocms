<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { fillDigits, typedDigit } from '../codefill';
import { t } from '../i18n';

/**
 * Code in Ziffernfeldern (TB 72): je Ziffer ein Feld. Tippen springt weiter, die Rücktaste in einem leeren Feld zurück,
 * Einfügen oder das Ausfüllen durch den Browser (`one-time-code`) verteilt die Ziffern. Sind alle Felder voll, meldet
 * die Komponente `complete` — das Formular sendet dann von selbst, ein Knopf «Anmelden» braucht es nicht.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        id: string;
        length?: number;
        invalid?: boolean;
        describedby?: string;
        disabled?: boolean;
        autofocus?: boolean;
        align?: 'center' | 'start';
    }>(),
    {
        length: 6,
        invalid: false,
        describedby: undefined,
        disabled: false,
        autofocus: true,
        align: 'center',
    },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    complete: [value: string];
}>();

const boxes = ref<HTMLInputElement[]>([]);
/** Je Feld eine Ziffer; eine Lücke in der Mitte steht im Wert als Leerzeichen (gesendet wird erst, wenn alles voll ist). */
const digits = computed(() =>
    Array.from({ length: props.length }, (_, i) =>
        (props.modelValue[i] ?? '').trim(),
    ),
);

function focus(index: number): void {
    void nextTick(() => {
        const box = boxes.value[Math.max(0, Math.min(index, props.length - 1))];
        box?.focus();
        box?.select();
    });
}

function update(chars: string[]): void {
    emit(
        'update:modelValue',
        chars
            .map((c) => c || ' ')
            .join('')
            .trimEnd(),
    );
}

/** Ziffern ab `index` eintragen (eine getippte oder viele eingefügte), dann weiter zum nächsten freien Feld. */
function fill(index: number, raw: string): void {
    const result = fillDigits(digits.value, index, raw);
    if (result === null) {
        return;
    }
    update(result.digits);
    if (result.complete) {
        boxes.value[props.length - 1]?.blur();
        emit('complete', result.digits.join(''));
    } else {
        focus(result.next);
    }
}

function onInput(index: number, event: Event): void {
    const box = event.target as HTMLInputElement;
    const typed = typedDigit(digits.value[index], box.value);
    box.value = digits.value[index];
    fill(index, typed);
}

function onPaste(index: number, event: ClipboardEvent): void {
    event.preventDefault();
    fill(index, event.clipboardData?.getData('text') ?? '');
}

function onKeydown(index: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace') {
        event.preventDefault();
        const chars = digits.value.slice();
        const at = chars[index] === '' ? index - 1 : index;
        if (at >= 0) {
            chars[at] = '';
            update(chars);
            focus(at);
        }
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        focus(index - 1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        focus(index + 1);
    }
}

// Nach einem falschen Code (Felder geleert, Prüfung vorbei) wieder vorne beginnen
watch(
    () => props.disabled,
    (disabled) => {
        if (!disabled && props.modelValue.trim() === '') {
            focus(0);
        }
    },
);

onMounted(() => {
    if (props.autofocus) {
        const free = digits.value.findIndex((c) => c === '');
        focus(free === -1 ? 0 : free);
    }
});
</script>

<template>
    <div
        :id="id"
        :class="[
            'flex gap-1.5 sm:gap-2',
            align === 'start' ? 'justify-start' : 'justify-center',
        ]"
        role="group"
        :aria-describedby="describedby"
    >
        <input
            v-for="(digit, i) in digits"
            :id="i === 0 ? `${id}-1` : undefined"
            :key="i"
            :ref="(el) => (boxes[i] = el as HTMLInputElement)"
            :value="digit"
            type="text"
            inputmode="numeric"
            :autocomplete="i === 0 ? 'one-time-code' : 'off'"
            maxlength="6"
            :disabled="disabled"
            :aria-label="t('Ziffer :n von :total', { n: i + 1, total: length })"
            :aria-invalid="invalid ? 'true' : undefined"
            :class="[
                'h-12 w-10 rounded-md border bg-white text-center font-mono text-xl font-semibold text-basalt focus:ring-2 focus:outline-none disabled:bg-paper sm:h-14 sm:w-12',
                invalid
                    ? 'border-danger ring-danger/15 focus:ring-danger ring-2'
                    : 'border-ash-dark focus:border-lava-dark focus:ring-lava-dark/40',
            ]"
            @input="onInput(i, $event)"
            @paste="onPaste(i, $event)"
            @keydown="onKeydown(i, $event)"
            @focus="($event.target as HTMLInputElement).select()"
        />
    </div>
</template>
