<script setup lang="ts">
/** Eine Wertezeile in einer Karte: Bezeichnung links, Wert rechts fett; feine Linie dazwischen. */
defineProps<{ label: string }>();
</script>

<template>
    <div
        class="flex justify-between gap-2.5 border-b border-ash-light py-[5px] text-[12.5px] last:border-b-0"
    >
        <dt class="shrink-0 text-basalt-light">{{ label }}</dt>
        <dd class="min-w-0 text-right font-semibold break-words">
            <slot />
        </dd>
    </div>
</template>
