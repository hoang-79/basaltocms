<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import Hex from './Hex.vue';
import { getJson, postJson } from '../http';
import { liveClient } from '../live/client';
import {
    desktopEnabled,
    desktopSupported,
    disableDesktop,
    enableDesktop,
    showDesktop,
} from '../live/desktop';
import { tc } from '../i18n';
import type { BackendProps, NotificationItem } from '../types';

/**
 * Die Glocke (TB 27) oben rechts im Backend und im Extranet: Zahl der ungelesenen in Lava, ein Klick öffnet die
 * neusten acht. Ungelesene tragen die rote Linie links (die Lava-Linie der Marke), gelesene sind still. Ein Klick auf
 * eine Benachrichtigung öffnet ihr Ziel (und wechselt wenn nötig die Site). Neues meldet die Live-Aktualisierung
 * (CR-008: Abfrage alle 15 s bei sichtbarem Tab, Push sofort, wo eingerichtet) — dann fragt sie die Zahl nach und
 * schlägt kurz aus (nicht mit «Bewegung reduzieren»); auf Wunsch kommt eine Desktop-Benachrichtigung.
 * Bedienung wie ein Dialog: Esc schliesst und gibt den Fokus zurück, ein Klick daneben schliesst.
 */
const page = usePage<BackendProps>();
const shared = computed(() => page.props.backend?.notifications ?? null);

const unread = ref(shared.value?.unread ?? 0);
const items = ref<NotificationItem[]>([]);
const loading = ref(false);
const failed = ref(false);
const open = ref(false);
const ring = ref(false);
const button = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
/** Auf schmalen Bildschirmen fest unter der Glocke über die ganze Breite — sonst liefe das Panel links hinaus */
const panelStyle = ref<Record<string, string>>({});

function place(): void {
    const rect = button.value?.getBoundingClientRect();
    panelStyle.value =
        rect && window.innerWidth < 640
            ? {
                  position: 'fixed',
                  left: '12px',
                  right: '12px',
                  top: `${rect.bottom + 6}px`,
                  width: 'auto',
              }
            : {};
}

watch(
    () => shared.value?.unread,
    (value) => {
        if (typeof value === 'number') {
            bump(value);
        }
    },
);

function bump(value: number): void {
    if (value > unread.value) {
        ring.value = false;
        void nextTick(() => {
            ring.value = true;
        });
    }
    unread.value = value;
}

const badge = computed(() =>
    unread.value > 99 ? '99+' : String(unread.value),
);

async function load(): Promise<void> {
    if (!shared.value) {
        return;
    }
    loading.value = true;
    failed.value = false;
    try {
        const data = await getJson<{
            unread: number;
            items: NotificationItem[];
        }>(shared.value.bell_url);
        items.value = data.items;
        bump(data.unread);
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

async function poll(force = false): Promise<void> {
    if (
        !shared.value ||
        (!force && document.visibilityState !== 'visible') ||
        open.value
    ) {
        return;
    }
    try {
        const data = await getJson<{ unread: number }>(
            `${shared.value.bell_url}?count=1`,
        );
        bump(data.unread);
    } catch {
        // still — beim nächsten Mal wieder
    }
}

function onOutside(event: PointerEvent): void {
    const target = event.target as Node | null;
    if (
        target &&
        !panel.value?.contains(target) &&
        !button.value?.contains(target)
    ) {
        close(false);
    }
}

async function show(): Promise<void> {
    place();
    open.value = true;
    document.addEventListener('pointerdown', onOutside);
    await load();
    await nextTick();
    panel.value?.querySelector<HTMLElement>('a, button')?.focus();
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

async function markRead(item: NotificationItem): Promise<void> {
    const data = await postJson<{ unread: number }>(item.read_url, {});
    item.read = !item.read;
    unread.value = data.unread;
}

async function markAll(): Promise<void> {
    if (!shared.value) {
        return;
    }
    await postJson<{ unread: number }>(shared.value.read_all_url, {});
    items.value.forEach((i) => (i.read = true));
    unread.value = 0;
}

/** Die Live-Aktualisierung meldet Neues: Zahl holen, bei mehr ungelesenen die neueste als Desktop-Benachrichtigung. */
async function refresh(): Promise<void> {
    const before = unread.value;
    await poll(desktopEnabled());
    if (unread.value <= before || !desktopEnabled() || !shared.value) {
        return;
    }
    try {
        const data = await getJson<{ items: NotificationItem[] }>(
            shared.value.bell_url,
        );
        const newest = data.items.find((item) => !item.read);
        if (newest) {
            showDesktop(newest.title, newest.body, newest.open_url, newest.id);
        }
    } catch {
        // still
    }
}

const desktop = ref(false);
const canDesktop = desktopSupported();
async function toggleDesktop(): Promise<void> {
    if (desktopEnabled()) {
        disableDesktop();
    } else {
        await enableDesktop();
    }
    desktop.value = desktopEnabled();
    liveClient().setBackground(desktop.value);
}

let timer: ReturnType<typeof setInterval> | null = null;
let stopLive: (() => void) | null = null;
function onVisible(): void {
    if (document.visibilityState === 'visible') {
        void poll();
    }
}

onMounted(() => {
    desktop.value = desktopEnabled();
    const live = shared.value?.live;
    if (live) {
        const client = liveClient();
        client.setBackground(desktop.value);
        stopLive = client.watch(
            live.site,
            live.key,
            live.token,
            live.version,
            () => void refresh(),
            live.interval,
        );
        const push = live.push;
        if (push) {
            void import('../live/push').then(({ connectPush }) =>
                connectPush(push, client),
            );
        }
    } else {
        timer = setInterval(() => void poll(), 60_000);
    }
    document.addEventListener('visibilitychange', onVisible);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }
    stopLive?.();
    document.removeEventListener('visibilitychange', onVisible);
    document.removeEventListener('pointerdown', onOutside);
});
</script>

<template>
    <div v-if="shared" class="relative">
        <button
            ref="button"
            type="button"
            class="relative flex h-8 w-8 items-center justify-center rounded-md text-paper hover:bg-basalt-light focus-visible:bg-basalt-light"
            aria-haspopup="dialog"
            :aria-expanded="open"
            aria-controls="glocke"
            :aria-label="
                unread > 0
                    ? tc(
                          'Benachrichtigungen, :count ungelesen|Benachrichtigungen, :count ungelesen',
                          unread,
                          { count: unread },
                      )
                    : $t('Benachrichtigungen')
            "
            :title="$t('Benachrichtigungen')"
            @click="toggle"
            @keydown.esc="close()"
        >
            <svg
                viewBox="0 0 16 16"
                class="h-[17px] w-[17px]"
                :class="ring ? 'bell-ring' : ''"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
                @animationend="ring = false"
            >
                <path
                    d="M4 11.5V7a4 4 0 0 1 8 0v4.5l1.25 1.25H2.75z M6.5 14a1.6 1.6 0 0 0 3 0"
                />
            </svg>
            <span
                v-if="unread > 0"
                class="absolute -top-0.5 -right-0.5 flex h-[17px] min-w-[17px] items-center justify-center rounded-full bg-lava px-1 font-mono text-[10px] leading-none font-semibold text-white ring-2 ring-basalt"
                aria-hidden="true"
                >{{ badge }}</span
            >
        </button>

        <!-- Nur im DOM, solange offen: ein versteckter Dialog verwirrt Screenreader und Prüfskripte («[role=dialog]») -->
        <div
            v-if="open"
            id="glocke"
            ref="panel"
            role="dialog"
            :aria-label="$t('Benachrichtigungen')"
            :style="panelStyle"
            class="absolute top-[calc(100%+6px)] right-0 z-40 w-[min(23rem,calc(100vw-1.5rem))] overflow-hidden rounded-lg border border-ash-light bg-white text-basalt shadow-[0_18px_40px_-18px_rgb(35_38_43/0.45)]"
            @keydown.esc.stop="close()"
        >
            <div
                class="flex items-center justify-between gap-3 border-b border-ash-light px-3.5 py-2.5"
            >
                <span class="text-[13px] font-semibold">
                    {{ $t('Benachrichtigungen') }}
                    <span
                        v-if="unread > 0"
                        class="ml-1 font-mono text-[11.5px] font-normal text-lava-dark"
                        >{{ unread }}</span
                    >
                </span>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="text-[12px] text-basalt-light underline hover:text-basalt"
                    @click="markAll"
                >
                    {{ $t('Alle gelesen') }}
                </button>
            </div>

            <p
                v-if="loading && items.length === 0"
                class="px-3.5 py-6 text-center text-[12.5px] text-basalt-light"
            >
                {{ $t('Lädt …') }}
            </p>
            <p
                v-else-if="failed"
                class="text-danger px-3.5 py-6 text-center text-[12.5px]"
                role="alert"
            >
                {{ $t('Die Benachrichtigungen liessen sich nicht laden.') }}
            </p>
            <div
                v-else-if="items.length === 0"
                class="flex flex-col items-center gap-1.5 px-3.5 py-7 text-center text-[12.5px] text-basalt-light"
            >
                <Hex icon="check" tone="success" size="md" />
                {{ $t('Nichts Neues. Hier erscheint, was Sie betrifft.') }}
            </div>
            <ul v-else class="scroll-y max-h-[min(26rem,70vh)]">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="group relative border-b border-ash-light last:border-b-0"
                >
                    <span
                        v-if="!item.read"
                        class="absolute inset-y-0 left-0 w-[3px] bg-lava"
                        aria-hidden="true"
                    />
                    <a
                        :href="item.open_url"
                        class="flex gap-2.5 py-2.5 pr-10 pl-3.5 hover:bg-paper focus:bg-paper focus:outline-none"
                    >
                        <Hex
                            :icon="item.icon"
                            :tone="item.read ? 'ash' : 'lava-dark'"
                            size="sm"
                        />
                        <span class="min-w-0">
                            <span
                                class="block text-[12.5px] leading-snug"
                                :class="
                                    item.read
                                        ? 'text-basalt-light'
                                        : 'font-semibold'
                                "
                                >{{ item.title }}</span
                            >
                            <span
                                v-if="item.body"
                                class="mt-0.5 line-clamp-2 block text-[12px] text-basalt-light"
                                >{{ item.body }}</span
                            >
                            <span
                                class="text-ash-dark mt-0.5 block font-mono text-[10.5px]"
                                >{{ item.at
                                }}<template v-if="item.site">
                                    · {{ item.site }}</template
                                ></span
                            >
                        </span>
                    </a>
                    <button
                        type="button"
                        class="text-ash-dark absolute top-2 right-2 flex h-7 w-7 items-center justify-center rounded-md opacity-0 group-hover:opacity-100 hover:bg-paper hover:text-basalt focus-visible:opacity-100"
                        :aria-label="
                            item.read
                                ? $t('Als ungelesen markieren')
                                : $t('Als gelesen markieren')
                        "
                        :title="
                            item.read
                                ? $t('Als ungelesen markieren')
                                : $t('Als gelesen markieren')
                        "
                        @click="markRead(item)"
                    >
                        <svg
                            viewBox="0 0 16 16"
                            class="h-3.5 w-3.5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            aria-hidden="true"
                        >
                            <path v-if="!item.read" d="M3 8.5l3 3 7-7" />
                            <circle v-else cx="8" cy="8" r="3" />
                        </svg>
                    </button>
                </li>
            </ul>

            <div
                class="flex items-center justify-between border-t border-ash-light bg-paper px-3.5 py-2 text-[12.5px]"
            >
                <Link
                    :href="shared.index_url"
                    class="font-semibold text-basalt underline"
                    @click="close(false)"
                    >{{ $t('Alle anzeigen') }}</Link
                >
                <span class="flex items-center gap-3">
                    <button
                        v-if="canDesktop"
                        type="button"
                        class="text-basalt-light underline hover:text-basalt"
                        :aria-pressed="desktop"
                        :title="
                            $t(
                                'Desktop-Benachrichtigungen, wenn das Backend im Hintergrund offen ist',
                            )
                        "
                        @click="toggleDesktop"
                    >
                        {{ desktop ? $t('Desktop: an') : $t('Desktop: aus') }}
                    </button>
                    <Link
                        :href="shared.settings_url"
                        class="text-basalt-light underline hover:text-basalt"
                        @click="close(false)"
                        >{{ $t('Einstellungen') }}</Link
                    >
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .bell-ring {
        transform-origin: 50% 10%;
        animation: bell-ring 0.9s ease-in-out;
    }
}

@keyframes bell-ring {
    0%,
    100% {
        transform: rotate(0);
    }
    20% {
        transform: rotate(14deg);
    }
    40% {
        transform: rotate(-11deg);
    }
    60% {
        transform: rotate(7deg);
    }
    80% {
        transform: rotate(-4deg);
    }
}
</style>
