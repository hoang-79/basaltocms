<script setup lang="ts">
/** Eine kleine Zustandsmarke (Mockup «tag»): Mono, Rand und Fläche in der Zustandsfarbe — nie eine volle rote Fläche. */
withDefaults(
    defineProps<{
        tone?: 'grey' | 'lava' | 'success' | 'warning' | 'danger';
    }>(),
    { tone: 'grey' },
);

const tones = {
    grey: 'border-ash bg-paper text-basalt-light',
    lava: 'border-lava-dark bg-lava/10 text-lava-dark',
    success: 'border-success bg-success/10 text-success',
    warning: 'border-warning bg-warning/10 text-warning',
    danger: 'border-danger bg-danger/10 text-danger',
};
</script>

<template>
    <span
        class="inline-block rounded border px-1.5 font-mono text-[10px] leading-[1.6] whitespace-nowrap"
        :class="tones[tone]"
        ><slot
    /></span>
</template>
