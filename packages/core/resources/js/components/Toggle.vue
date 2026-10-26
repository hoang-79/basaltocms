<script setup lang="ts">
/**
 * Ein Schalter (Mockup «schalter-an/aus»): grün für ein, Asche dunkel für aus (3:1 zur Fläche, WCAG 1.4.11);
 * gesperrt mit halber Deckkraft. Die Spur ist 18 px hoch, der Knopf darum 24 px (Zielgrösse, WCAG 2.5.8).
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        disabled?: boolean;
        label?: string;
    }>(),
    { disabled: false, label: '' },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>();

function toggle(): void {
    if (!props.disabled) {
        emit('update:modelValue', !props.modelValue);
    }
}
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="label || undefined"
        :disabled="disabled"
        class="group inline-flex h-6 w-8 shrink-0 items-center rounded-full disabled:cursor-not-allowed disabled:opacity-50"
        @click="toggle"
    >
        <span
            class="flex h-[18px] w-8 items-center rounded-full p-0.5 transition-colors"
            :class="
                modelValue
                    ? 'bg-success justify-end'
                    : 'bg-ash-dark justify-start'
            "
        >
            <span class="block h-3.5 w-3.5 rounded-full bg-white" />
        </span>
    </button>
</template>
