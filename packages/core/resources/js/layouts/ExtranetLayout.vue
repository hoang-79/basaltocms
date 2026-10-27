<script setup lang="ts">
import ReleaseBadge from '../components/ReleaseBadge.vue';
import UserMenu from '../components/UserMenu.vue';
import NotificationBell from '../components/NotificationBell.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AutosaveToast from '../components/AutosaveToast.vue';
import HelpPanel from '../components/HelpPanel.vue';
import Hex from '../components/Hex.vue';
import Notice from '../components/Notice.vue';
import Wordmark from '../components/Wordmark.vue';
import { isCurrent, initials } from '../support';
import type { BackendProps } from '../types';

/**
 * Der Rahmen des Hersteller-Extranets (TB 19, CR-004 P-09): Kopfzeile Basalt mit Wortmarke und dem Abzeichen
 * «Extranet» in Lava — so ist jederzeit klar, in welcher Schicht man arbeitet (Muster aus Fristo). Darunter das Menü
 * nach den Rechten der Rolle mit Zahlen, wo etwas wartet. Kein CMS-Modus, keine rote Linie. Keine Scrollbalken:
 * die Verlaufskante mit Chevron zeigt, dass es weitergeht; das Menü bricht um.
 */
const page = usePage<BackendProps>();
const extranet = computed(() => page.props.extranet ?? null);
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.backend?.flash?.status ?? null);
const current = (href: string): boolean =>
    href === extranet.value?.home
        ? new URL(href, window.location.origin).pathname ===
          new URL(page.url, window.location.origin).pathname
        : isCurrent(page.url, href);

const moreBelow = ref(false);
let observer: ResizeObserver | null = null;

function measure(): void {
    const el = document.documentElement;
    moreBelow.value = el.scrollHeight - el.clientHeight - window.scrollY > 8;
}

onMounted(() => {
    measure();
    window.addEventListener('scroll', measure, { passive: true });
    window.addEventListener('resize', measure);
    observer = new ResizeObserver(measure);
    observer.observe(document.body);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', measure);
    window.removeEventListener('resize', measure);
    observer?.disconnect();
});
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a
            href="#inhalt"
            class="sr-only rounded-md bg-paper px-3 py-2 text-[13px] font-semibold text-basalt focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50"
            >{{ $t('Zum Inhalt springen') }}</a
        >
        <header class="bg-basalt text-paper">
            <div
                class="mx-auto flex min-h-12 max-w-6xl flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-1.5 md:h-12 md:flex-nowrap md:py-0"
            >
                <Link
                    :href="extranet?.home ?? '/'"
                    class="text-[17px]"
                    :aria-label="$t('Extranet — Übersicht')"
                >
                    <Wordmark tone="basalt" />
                </Link>
                <span
                    class="rounded-md bg-lava px-2 py-[3px] text-[10.5px] font-semibold tracking-[.12em] text-white uppercase"
                    >{{ $t('Extranet') }}</span
                >
                <span
                    v-if="extranet?.role"
                    class="hidden text-xs text-ash-light sm:inline"
                    >{{ extranet.role }}</span
                >
                <a
                    v-if="extranet"
                    :href="extranet.cms_url"
                    class="ml-auto text-[11.5px] text-ash-light underline hover:text-paper"
                    >{{ $t('Zum CMS') }}</a
                >
                <div class="flex items-center gap-1.5">
                    <NotificationBell v-if="extranet" />
                    <UserMenu
                        v-if="user && extranet"
                        :user="user"
                        :profile-url="page.props.backend?.profile_url"
                        :settings-url="page.props.backend?.settings_url"
                        :logout-url="extranet.logout_url"
                        :help="Boolean(page.props.backend?.help)"
                    />
                </div>
            </div>
        </header>

        <nav
            class="border-b border-ash-light bg-white"
            :aria-label="$t('Extranet')"
        >
            <div
                class="mx-auto flex min-h-[50px] max-w-6xl flex-wrap items-stretch px-3.5"
            >
                <Link
                    v-for="item in extranet?.menu ?? []"
                    :key="item.route"
                    :href="item.href"
                    class="-mb-px flex min-h-8 items-center gap-[7px] border-b-2 px-[11px] text-[12.5px] whitespace-nowrap"
                    :class="
                        current(item.href)
                            ? 'border-lava font-semibold text-basalt'
                            : 'border-transparent text-basalt-light hover:text-basalt'
                    "
                    :aria-current="current(item.href) ? 'page' : undefined"
                >
                    <Hex
                        :icon="item.icon"
                        :label="item.label"
                        :tone="current(item.href) ? 'basalt' : 'ash'"
                    />
                    {{ item.label }}
                    <span
                        v-if="item.count"
                        class="border-warning text-warning rounded border bg-paper px-[5px] font-mono text-[10.5px] font-normal"
                        :aria-label="$t(':count offen', { count: item.count })"
                        >{{ item.count }}</span
                    >
                </Link>
            </div>
        </nav>

        <main
            id="inhalt"
            tabindex="-1"
            class="mx-auto w-full max-w-6xl flex-1 px-4 py-5 focus:outline-none md:px-6"
        >
            <Notice v-if="flash" tone="success" class="mb-4">{{
                flash
            }}</Notice>
            <slot />
        </main>
        <AutosaveToast />
        <!-- TB 29: die Webhilfe auch im Extranet — «?», F1 und das Benutzermenü öffnen sie -->
        <HelpPanel />

        <div
            aria-hidden="true"
            class="pointer-events-none fixed inset-x-0 bottom-0 z-20 flex h-10 items-end justify-center bg-linear-to-t from-paper to-paper/0 pb-1.5 transition-opacity duration-300"
            :class="moreBelow ? 'opacity-100' : 'opacity-0'"
        >
            <svg
                viewBox="0 0 12 8"
                class="h-2 w-3 text-lava"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
            >
                <path d="M1 1l5 5 5-5" />
            </svg>
        </div>
        <ReleaseBadge />
    </div>
</template>
