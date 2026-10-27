<script setup lang="ts">
import { computed, useId } from 'vue';
import { controlClasses, describedByIds, labelClasses } from './control';

/**
 * Ein Formularfeld: Label in Versalien klein, Eingabe, Fehler karmin am Feld, Hilfetext darunter.
 * Ohne Slot rendert es Eingabe, Auswahl oder Textbereich nach `type`; mit Slot nur den Rahmen
 * (der Slot bekommt `id`, `invalid` und `describedby`). Fehler und Hilfe hängen über `aria-describedby`
 * am Feld, ein Fehler setzt `aria-invalid` (WCAG 3.3.1, 1.3.1).
 */
const props = withDefaults(
    defineProps<{
        label: string;
        modelValue?: string | number | null;
        type?: 'text' | 'email' | 'number' | 'search' | 'select' | 'textarea';
        options?: (string | { value: string; label: string })[];
        placeholder?: string;
        help?: string;
        error?: string;
        optional?: boolean;
        required?: boolean;
        disabled?: boolean;
        mono?: boolean;
        autocomplete?: string;
        inputmode?: 'text' | 'numeric' | 'email' | 'url';
        pattern?: string;
        maxlength?: number;
        rows?: number;
        id?: string;
        /** Autosave: Häkchen «gespeichert» am Label für einen Moment */
        saved?: boolean;
        /** Autosave «beim Verlassen» (CR-013): Die Änderung wartet auf Enter oder das Verlassen des Felds */
        waiting?: boolean;
    }>(),
    {
        modelValue: '',
        type: 'text',
        options: () => [],
        placeholder: '',
        help: '',
        error: '',
        optional: false,
        required: false,
        disabled: false,
        mono: false,
        autocomplete: undefined,
        inputmode: undefined,
        pattern: undefined,
        maxlength: undefined,
        rows: 3,
        id: undefined,
        saved: false,
        waiting: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const generated = useId();
const fieldId = computed(() => props.id ?? `field-${generated}`);
const slots = defineSlots<{
    default?(props: {
        id: string;
        invalid: boolean;
        describedby: string | undefined;
    }): unknown;
    help?(): unknown;
}>();
const describedBy = computed(() =>
    describedByIds(fieldId.value, {
        error: Boolean(props.error),
        help: Boolean(props.help || slots.help),
    }),
);

const classes = computed(() =>
    controlClasses({ error: Boolean(props.error), mono: props.mono }),
);

const normalizedOptions = computed(() =>
    props.options.map((o) =>
        typeof o === 'string' ? { value: o, label: o } : o,
    ),
);

function update(event: Event): void {
    const target = event.target as
        | HTMLInputElement
        | HTMLSelectElement
        | HTMLTextAreaElement;
    emit('update:modelValue', target.value);
}
</script>

<template>
    <div class="mt-2.5 first:mt-0">
        <label :for="fieldId" :class="labelClasses">
            {{ label
            }}<span
                v-if="optional"
                class="text-ash-dark ml-1 font-normal tracking-normal normal-case"
                >{{ $t('freiwillig') }}</span
            ><Transition
                enter-active-class="transition duration-200 motion-reduce:transition-none"
                enter-from-class="opacity-0"
                leave-active-class="transition duration-500 motion-reduce:transition-none"
                leave-to-class="opacity-0"
                ><span
                    v-if="saved"
                    class="text-success ml-1.5 inline-flex items-center gap-0.5 font-semibold tracking-normal normal-case"
                    >✓ {{ $t('gespeichert') }}</span
                ></Transition
            ><span
                v-if="waiting && !saved"
                class="ml-1.5 font-normal tracking-normal text-basalt-light normal-case"
                >{{
                    type === 'textarea'
                        ? $t('Feld verlassen speichert')
                        : $t('Enter oder Feld verlassen speichert')
                }}</span
            >
        </label>
        <slot
            :id="fieldId"
            :invalid="Boolean(error)"
            :describedby="describedBy"
        >
            <select
                v-if="type === 'select'"
                :id="fieldId"
                :value="modelValue ?? ''"
                :required="required"
                :disabled="disabled"
                :class="classes"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                @change="update"
            >
                <option
                    v-for="o in normalizedOptions"
                    :key="o.value"
                    :value="o.value"
                >
                    {{ o.label }}
                </option>
            </select>
            <textarea
                v-else-if="type === 'textarea'"
                :id="fieldId"
                :value="modelValue ?? ''"
                :rows="rows"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :class="classes"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                @input="update"
            />
            <input
                v-else
                :id="fieldId"
                :type="type"
                :value="modelValue ?? ''"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :autocomplete="autocomplete"
                :inputmode="inputmode"
                :pattern="pattern"
                :maxlength="maxlength"
                :class="classes"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                @input="update"
            />
        </slot>
        <p
            v-if="error"
            :id="`${fieldId}-error`"
            class="text-danger mt-1 text-[11.5px] font-semibold"
            role="alert"
        >
            {{ error }}
        </p>
        <p
            v-if="help || $slots.help"
            :id="`${fieldId}-help`"
            class="mt-1 text-[11.5px] text-basalt-light"
        >
            <slot name="help">{{ help }}</slot>
        </p>
    </div>
</template>
