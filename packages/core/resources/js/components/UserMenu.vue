<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, ref } from 'vue';
import Hex from './Hex.vue';
import { openHelp } from './help';
import { initials } from '../support';

/**
 * Benutzermenü (TB 21) oben rechts im Backend und im Extranet: Profilbild oder Initialen mit Namen; ein Klick öffnet
 * Name und E-Mail, «Profil», «Einstellungen», «Hilfe und Tastenkürzel» (nur mit Hilfe-Panel) und «Abmelden».
 * Bedienung wie ein Menü (WAI-ARIA «menu button»): ↑ ↓ Pos1 Ende wählen, Esc schliesst und gibt den Fokus zurück, ein
 * Klick daneben oder Tab schliesst.
 */
const props = withDefaults(
    defineProps<{
        user: { name: string; email: string; avatar_url?: string | null };
        profileUrl?: string | null;
        settingsUrl?: string | null;
        logoutUrl: string;
        help?: boolean;
    }>(),
    { profileUrl: null, settingsUrl: null, help: false },
);

const open = ref(false);
const button = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLElement | null>(null);

function items(): HTMLElement[] {
    return Array.from(
        menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [],
    );
}

function onOutside(event: PointerEvent): void {
    const target = event.target as Node | null;
    if (
        target &&
        !menu.value?.contains(target) &&
        !button.value?.contains(target)
    ) {
        close(false);
    }
}

async function show(focus: 'first' | 'last' = 'first'): Promise<void> {
    open.value = true;
    document.addEventListener('pointerdown', onOutside);
    await nextTick();
    const list = items();
    (focus === 'first' ? list[0] : list[list.length - 1])?.focus();
}

function close(returnFocus = true): void {
    open.value = false;
    document.removeEventListener('pointerdown', onOutside);
    if (returnFocus) {
        button.value?.focus();
    }
}

function toggle(): void {
    if (open.value) {
        close();
    } else {
        void show();
    }
}

function onButtonKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        void show(event.key === 'ArrowDown' ? 'first' : 'last');
    }
}

function onMenuKey(event: KeyboardEvent): void {
    const list = items();
    const index = list.indexOf(document.activeElement as HTMLElement);
    let next: number | null = null;
    if (event.key === 'ArrowDown') {
        next = (index + 1) % list.length;
    } else if (event.key === 'ArrowUp') {
        next = (index - 1 + list.length) % list.length;
    } else if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = list.length - 1;
    } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();

        return;
    } else if (event.key === 'Tab') {
        close(false);

        return;
    }
    if (next !== null) {
        event.preventDefault();
        list[next]?.focus();
    }
}

function help(): void {
    close(false);
    openHelp(null);
}

function logout(): void {
    close(false);
    router.post(props.logoutUrl);
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside));
</script>

<template>
    <div class="relative ml-auto md:ml-0">
        <button
            ref="button"
            type="button"
            class="flex items-center gap-[7px] rounded-md px-1 py-0.5 text-xs hover:bg-basalt-light focus-visible:bg-basalt-light"
            aria-haspopup="menu"
            :aria-expanded="open"
            aria-controls="benutzermenue"
            :title="user.name"
            @click="toggle"
            @keydown="onButtonKey"
        >
            <Hex
                tone="lava-dark"
                size="md"
                :label="initials(user.name)"
                :image="user.avatar_url ?? null"
            />
            <span class="hidden whitespace-nowrap lg:inline">{{
                user.name
            }}</span>
            <span class="sr-only lg:hidden">{{ user.name }}</span>
            <svg
                viewBox="0 0 12 8"
                class="h-1.5 w-2.5 text-ash transition-transform"
                :class="open ? 'rotate-180' : ''"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <path d="M1 1l5 5 5-5" />
            </svg>
        </button>
        <div
            v-show="open"
            id="benutzermenue"
            ref="menu"
            role="menu"
            :aria-label="$t('Benutzermenü')"
            class="absolute top-[calc(100%+6px)] right-0 z-40 w-64 overflow-hidden rounded-lg border border-ash-light bg-white text-basalt shadow-[0_18px_40px_-18px_rgb(35_38_43/0.45)]"
            @keydown="onMenuKey"
        >
            <div
                class="flex items-center gap-2.5 border-b border-ash-light px-3.5 py-3"
                role="none"
            >
                <Hex
                    tone="lava-dark"
                    size="lg"
                    :label="initials(user.name)"
                    :image="user.avatar_url ?? null"
                />
                <span class="min-w-0">
                    <span class="block truncate text-[13px] font-semibold">{{
                        user.name
                    }}</span>
                    <span
                        class="block truncate text-[11.5px] text-basalt-light"
                        >{{ user.email }}</span
                    >
                </span>
            </div>
            <div class="grid py-1 text-[13px]" role="none">
                <Link
                    v-if="profileUrl"
                    :href="profileUrl"
                    role="menuitem"
                    tabindex="-1"
                    class="px-3.5 py-2 hover:bg-paper focus:bg-paper focus:outline-none"
                    @click="close(false)"
                    >{{ $t('Profil') }}</Link
                >
                <Link
                    v-if="settingsUrl"
                    :href="settingsUrl"
                    role="menuitem"
                    tabindex="-1"
                    class="px-3.5 py-2 hover:bg-paper focus:bg-paper focus:outline-none"
                    @click="close(false)"
                    >{{ $t('Einstellungen') }}</Link
                >
                <button
                    v-if="help"
                    type="button"
                    role="menuitem"
                    tabindex="-1"
                    class="flex items-center justify-between px-3.5 py-2 text-left hover:bg-paper focus:bg-paper focus:outline-none"
                    @click="help"
                >
                    {{ $t('Hilfe und Tastenkürzel') }}
                    <kbd
                        class="rounded border border-ash-light bg-paper px-1.5 font-mono text-[11px] text-basalt-light"
                        >F1</kbd
                    >
                </button>
            </div>
            <div class="border-t border-ash-light py-1 text-[13px]" role="none">
                <button
                    type="button"
                    role="menuitem"
                    tabindex="-1"
                    class="w-full px-3.5 py-2 text-left text-lava-dark hover:bg-paper focus:bg-paper focus:outline-none"
                    @click="logout"
                >
                    {{ $t('Abmelden') }}
                </button>
            </div>
        </div>
    </div>
</template>
