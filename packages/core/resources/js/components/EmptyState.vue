<script setup lang="ts">
import Hex from './Hex.vue';

/** Der Leerzustand: Erfolg sieht anders aus als Aufgabe — grünes Sechseck, bestätigender Satz, kein Knopf nötig. */
withDefaults(
    defineProps<{
        title: string;
        text?: string;
        icon?: string;
        tone?: 'success' | 'ash' | 'lava';
    }>(),
    { text: '', icon: 'check', tone: 'success' },
);
</script>

<template>
    <div
        class="flex flex-col items-center gap-[7px] px-4 py-6 text-center text-basalt-light"
    >
        <Hex :icon="icon" :tone="tone" size="lg" />
        <strong class="text-sm font-semibold text-basalt">{{ title }}</strong>
        <span v-if="text" class="max-w-[36ch] text-[12.5px]">{{ text }}</span>
        <div v-if="$slots.default" class="mt-1">
            <slot />
        </div>
    </div>
</template>
