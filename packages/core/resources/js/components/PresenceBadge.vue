<script setup lang="ts">
import { computed } from 'vue';
import type { PresentPerson } from './presence';

/**
 * TB 49: «Anna bearbeitet diesen Inhalt auch gerade» — Sechsecke mit Initialen und ein Satz. Als Statusmeldung, damit
 * Screenreader es hören, wenn jemand dazukommt.
 */
const props = defineProps<{ people: PresentPerson[] }>();

const initials = (name: string): string =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');

const shown = computed(() => props.people.slice(0, 3));
</script>

<template>
    <p role="status" class="m-0">
        <span
            v-if="people.length > 0"
            class="border-lava-light inline-flex items-center gap-2 rounded-full border bg-lava/10 py-1 pr-3 pl-1.5 text-[12.5px] font-semibold text-basalt"
        >
            <span class="flex -space-x-1.5" aria-hidden="true">
                <span
                    v-for="person in shown"
                    :key="person.id"
                    class="hex flex h-6 w-7 items-center justify-center bg-basalt text-[9.5px] font-bold text-white ring-2 ring-white"
                    >{{ initials(person.name) }}</span
                >
            </span>
            <span class="relative flex h-2 w-2" aria-hidden="true">
                <span
                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-lava opacity-60 motion-reduce:hidden"
                ></span>
                <span
                    class="relative inline-flex h-2 w-2 rounded-full bg-lava"
                ></span>
            </span>
            {{
                people.length === 1
                    ? $t(':name bearbeitet diesen Inhalt auch gerade', {
                          name: people[0].name,
                      })
                    : $tc(
                          ':count Personen bearbeiten diesen Inhalt auch gerade|:count Personen bearbeiten diesen Inhalt auch gerade',
                          people.length,
                      )
            }}
        </span>
    </p>
</template>
