<script setup lang="ts">
/** Eine Hinweisfläche (Mockup «hinweis»): getönte Fläche mit linker Linie in der Zustandsfarbe, Titel freiwillig. */
withDefaults(
    defineProps<{
        tone?: 'lava' | 'warning' | 'danger' | 'success' | 'grey' | 'dark';
        title?: string;
    }>(),
    { tone: 'lava', title: '' },
);

const tones = {
    lava: 'border-lava bg-lava/10 text-basalt',
    warning: 'border-warning bg-warning/10 text-warning',
    danger: 'border-danger bg-danger/10 text-danger',
    success: 'border-success bg-success/10 text-success',
    grey: 'border-ash bg-white text-basalt-light',
    dark: 'border-lava bg-basalt text-paper',
};
</script>

<template>
    <div
        class="rounded-lg border-l-[3px] px-[13px] py-2.5 text-[12.5px] leading-relaxed"
        :class="tones[tone]"
        role="status"
    >
        <h5 v-if="title" class="mb-[3px] text-[13px] font-semibold">
            {{ title }}
        </h5>
        <slot />
    </div>
</template>
