<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Knöpfe nach gestaltung.md § 7: primär Lava dunkel mit Weiss, sekundär Weiss mit Rand, Link Lava dunkel unterstrichen.
 * Mit `href` ein Inertia-Link (bei `method` ≠ get als Knopf), sonst ein Knopf.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'link' | 'danger' | 'dark';
        size?: 'sm' | 'md';
        wide?: boolean;
        href?: string;
        method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
        type?: 'button' | 'submit';
        disabled?: boolean;
    }>(),
    {
        variant: 'primary',
        size: 'md',
        wide: false,
        href: undefined,
        method: 'get',
        type: 'button',
        disabled: false,
    },
);

const variants: Record<NonNullable<typeof props.variant>, string> = {
    primary:
        'border-transparent bg-lava-dark text-white hover:brightness-90 disabled:border-ash-light disabled:bg-white disabled:text-ash',
    secondary:
        'border-ash bg-white text-basalt hover:bg-paper disabled:border-ash-light disabled:text-ash',
    link: 'border-transparent bg-transparent px-1 text-lava-dark underline hover:text-basalt disabled:text-ash disabled:no-underline',
    danger: 'border-danger bg-white text-danger hover:bg-danger/5 disabled:border-ash-light disabled:text-ash',
    dark: 'border-transparent bg-basalt text-paper hover:bg-basalt-light disabled:bg-ash-light disabled:text-ash',
};

const classes = computed(() =>
    [
        'inline-flex items-center justify-center whitespace-nowrap rounded-md border font-semibold leading-tight transition-colors disabled:cursor-not-allowed',
        props.size === 'sm'
            ? 'px-2.5 py-[5px] text-xs'
            : 'px-[13px] py-2 text-[13px]',
        props.wide ? 'w-full py-[11px]' : '',
        variants[props.variant],
    ]
        .filter(Boolean)
        .join(' '),
);
</script>

<template>
    <Link
        v-if="href && !disabled"
        :href="href"
        :method="method"
        :as="method === 'get' ? 'a' : 'button'"
        :class="classes"
    >
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
