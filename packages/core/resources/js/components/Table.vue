<script setup lang="ts">
/**
 * Eine Liste als Tabelle: eine weisse Fläche, feine Linien, Spaltentitel in Versalien klein.
 * Zellen setzen `px-3 py-2.5` und `data-label` mit dem Spaltentitel; unter 768 px werden Zeilen zu Karten
 * (keine waagrechte Scrollleiste). Der Fuss (Slot «foot») trägt Zahl und Hinweis.
 */
</script>

<template>
    <div class="table-stack rounded-lg border border-ash-light bg-white">
        <table class="w-full text-sm">
            <thead
                v-if="$slots.head"
                class="border-b border-ash text-left text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
            >
                <slot name="head" />
            </thead>
            <tbody class="divide-y divide-ash-light">
                <slot />
            </tbody>
        </table>
        <div
            v-if="$slots.foot"
            class="border-t border-ash-light bg-paper px-3 py-2 text-[11.5px] text-basalt-light"
        >
            <slot name="foot" />
        </div>
    </div>
</template>
