<script setup lang="ts">
/**
 * Eine Karte: Weiss, Rand Asche hell, Radius 8 px. Der Zustand steht als kleines Sechseck vor dem Titel —
 * keine Farbkante (Mockup E-24).
 */
withDefaults(
    defineProps<{
        title?: string;
        sub?: string;
        status?: 'success' | 'warning' | 'danger' | 'lava' | null;
        dark?: boolean;
    }>(),
    { title: '', sub: '', status: null, dark: false },
);

const marks = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    lava: 'bg-lava',
};

const borders = {
    success: 'border-success/40',
    warning: 'border-warning/50',
    danger: 'border-danger/35',
    lava: 'border-lava/40',
};
</script>

<template>
    <section
        class="rounded-lg border px-3.5 py-3"
        :class="[
            dark
                ? 'border-basalt-light bg-basalt text-paper'
                : 'bg-white text-basalt',
            status ? borders[status] : 'border-ash-light',
        ]"
    >
        <div
            v-if="title || $slots.title || $slots.right"
            class="flex items-baseline justify-between gap-2 text-sm font-semibold"
        >
            <span class="inline-flex items-center">
                <span
                    v-if="status"
                    class="hex mr-[7px] inline-block h-2.5 w-[11px] shrink-0"
                    :class="marks[status]"
                    aria-hidden="true"
                />
                <slot name="title">{{ title }}</slot>
            </span>
            <span
                v-if="$slots.right"
                class="text-[11.5px] font-normal"
                :class="dark ? 'text-ash' : 'text-basalt-light'"
            >
                <slot name="right" />
            </span>
        </div>
        <div
            v-if="sub || $slots.sub"
            class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs"
            :class="dark ? 'text-ash' : 'text-basalt-light'"
        >
            <slot name="sub">{{ sub }}</slot>
        </div>
        <div
            v-if="$slots.default"
            :class="{ 'mt-2': title || sub || $slots.title || $slots.sub }"
        >
            <slot />
        </div>
    </section>
</template>
