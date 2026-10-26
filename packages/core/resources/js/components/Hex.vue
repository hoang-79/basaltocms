<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * Das Sechseck als Symbolgrund (gestaltung.md § 7): ein Symbol nach Name, sonst ein Buchstabe.
 * Kreise sind Stationen, Sechsecke sind Dinge und Zustände (Mockup E-24).
 */
const props = withDefaults(
    defineProps<{
        icon?: string | null;
        label?: string;
        tone?:
            | 'ash'
            | 'basalt'
            | 'dark'
            | 'lava'
            | 'lava-dark'
            | 'success'
            | 'warning'
            | 'danger'
            | 'white';
        size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
        title?: string;
        /** TB 21: ein Bild (Profilbild) statt Symbol oder Buchstaben, im Sechseck zugeschnitten */
        image?: string | null;
    }>(),
    {
        icon: null,
        label: '',
        tone: 'ash',
        size: 'sm',
        title: undefined,
        image: null,
    },
);

/** Einfache Strichsymbole im 16×16-Raster; Namen wie in den Menüeinträgen des Kerns. */
const icons: Record<string, string> = {
    overview: 'M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z',
    dashboard: 'M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z',
    pages: 'M4 1.5h5.5L13 5v9.5H4z M9.5 1.5V5H13',
    entries: 'M4 1.5h5.5L13 5v9.5H4z M9.5 1.5V5H13',
    content: 'M4 1.5h5.5L13 5v9.5H4z M9.5 1.5V5H13',
    media: 'M2 3h12v10H2z M2 11l3.5-3.5 3 3 2-2L14 12 M10.5 6.5h.01',
    module: 'M8 1.5 14 5v6l-6 3.5L2 11V5z M8 8l6-3 M8 8 2 5 M8 8v6.5',
    modules: 'M8 1.5 14 5v6l-6 3.5L2 11V5z M8 8l6-3 M8 8 2 5 M8 8v6.5',
    user: 'M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M2.5 14.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5',
    users: 'M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M2.5 14.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5',
    blog: 'M11.5 2.5l2 2L5 13H3v-2z',
    forms: 'M3 4h10M3 8h10M3 12h6',
    comments: 'M2 3h12v8H6l-3 3v-3H2z',
    translation: 'M2 5h9l-2.5-2.5M14 11H5l2.5 2.5',
    review: 'M3 8.5l3 3 7-7',
    sites: 'M8 14.5A6.5 6.5 0 1 0 8 1.5a6.5 6.5 0 0 0 0 13z M1.5 8h13 M8 1.5c2 2 2 11 0 13 M8 1.5c-2 2-2 11 0 13',
    site: 'M8 14.5A6.5 6.5 0 1 0 8 1.5a6.5 6.5 0 0 0 0 13z M1.5 8h13 M8 1.5c2 2 2 11 0 13 M8 1.5c-2 2-2 11 0 13',
    settings: 'M2 4h12M2 8h12M2 12h12 M5 2.5v3M11 6.5v3M7 10.5v3',
    activity:
        'M8 14.5A6.5 6.5 0 1 0 8 1.5a6.5 6.5 0 0 0 0 13z M8 4.5V8l2.5 1.5',
    log: 'M8 14.5A6.5 6.5 0 1 0 8 1.5a6.5 6.5 0 0 0 0 13z M8 4.5V8l2.5 1.5',
    license:
        'M9.5 6.5a3 3 0 1 0-3-3 3 3 0 0 0 3 3z M7.5 6.5 2 12v2h2v-1.5h1.5V11H7l1.5-1.5',
    passkeys:
        'M9.5 6.5a3 3 0 1 0-3-3 3 3 0 0 0 3 3z M7.5 6.5 2 12v2h2v-1.5h1.5V11H7l1.5-1.5',
    operations: 'M1.5 8h3l2-4 3 8 2-4h3',
    // TB 54: Statistik — Balken
    statistics: 'M2.5 14.5h11 M4 12V8 M7 12V4 M10 12V6.5 M13 12V9.5',
    updates: 'M13.5 8A5.5 5.5 0 1 1 11.9 4.1 M12.5 1.5v3h-3',
    theme: 'M8 1.5 13.6 4.75v6.5L8 14.5 2.4 11.25v-6.5z',
    setup: 'M1.5 8h13 M8 5a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    line: 'M1.5 8h13 M8 5a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    check: 'M3 8.5l3 3 7-7',
    cross: 'M4 4l8 8M12 4l-8 8',
    folder: 'M2 4h4.5l1.5 1.5H14V13H2z',
    redirect: 'M2.5 8h9 M8 4.5 11.5 8 8 11.5',
    feed: 'M3 13h.01 M3 8.5A4.5 4.5 0 0 1 7.5 13 M3 3.5A9.5 9.5 0 0 1 12.5 13',
    home: 'M2 8.5 8 3l6 5.5 M4 7.5V14h8V7.5 M6.5 14v-4h3v4',
    error: 'M8 2.5 14 13H2z M8 7v2.5 M8 11.2h.01',
    type: 'M3 3.5h10 M8 3.5v9 M5.5 12.5h5',
};

const path = computed(() => (props.icon ? icons[props.icon] : undefined));

/** Lädt das Bild nicht (Datei weg, Speicher nicht erreichbar), erscheinen wieder Symbol oder Buchstaben statt eines kaputten Bilds */
const broken = ref(false);
watch(
    () => props.image,
    () => {
        broken.value = false;
    },
);
const letter = computed(() => props.label.slice(0, 2));

const sizes: Record<NonNullable<typeof props.size>, string> = {
    xs: 'h-[13px] w-3.5 text-[8px]',
    sm: 'h-[18px] w-5 text-[10.5px]',
    md: 'h-[25px] w-7 text-[10px]',
    lg: 'h-[27px] w-[30px] text-sm',
    xl: 'h-[56px] w-[64px] text-lg',
};

const tones: Record<NonNullable<typeof props.tone>, string> = {
    ash: 'bg-ash-light text-basalt-light',
    basalt: 'bg-basalt text-paper',
    dark: 'bg-basalt-light text-paper',
    lava: 'bg-lava text-white',
    'lava-dark': 'bg-lava-dark text-white',
    success: 'bg-success text-white',
    warning: 'bg-warning text-white',
    danger: 'bg-danger text-white',
    white: 'bg-white text-basalt',
};
</script>

<template>
    <span
        class="hex inline-flex shrink-0 items-center justify-center leading-none font-semibold"
        :class="[sizes[size], tones[tone]]"
        :title="title"
        :aria-hidden="title ? undefined : 'true'"
    >
        <img
            v-if="image && !broken"
            :src="image"
            alt=""
            class="h-full w-full object-cover"
            @error="broken = true"
        />
        <svg
            v-else-if="path"
            viewBox="0 0 16 16"
            class="h-[62%] w-[62%]"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path :d="path" />
        </svg>
        <span v-else>{{ letter }}</span>
    </span>
</template>
