<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ReleaseBadge from '../components/ReleaseBadge.vue';
import Hex from '../components/Hex.vue';
import AutosaveToast from '../components/AutosaveToast.vue';
import BackendSearch from '../components/BackendSearch.vue';
import HelpPanel from '../components/HelpPanel.vue';
import UserMenu from '../components/UserMenu.vue';
import NotificationBell from '../components/NotificationBell.vue';
import DemoBanner from '../components/DemoBanner.vue';
import AccountExitBanner from '../components/AccountExitBanner.vue';
import TrialBanner from '../components/TrialBanner.vue';
import SupportBanner from '../components/SupportBanner.vue';
import ModeBar from '../components/ModeBar.vue';
import Notice from '../components/Notice.vue';
import Wordmark from '../components/Wordmark.vue';
import { t } from '../i18n';
import { initials } from '../support';
import type { BackendProps } from '../types';

/**
 * Der Backend-Rahmen in der Identität «Rote Linie» (gestaltung.md § 7, E-21):
 * Kopfzeile Basalt mit Wortmarke, Site-Wechsler, den zwei Modi Inhalte · Einrichtung, ⌘K-Feld, Konto;
 * darunter die Modus-Leiste; die Arbeitsfläche auf Papier. Keine Scrollbalken: solange unten mehr folgt,
 * zeigt eine Verlaufskante in Papier mit Lava-Chevron, dass die Seite weitergeht.
 */
const page = usePage<BackendProps>();
const backend = computed(() => page.props.backend);
const user = computed(() => page.props.auth.user);
const site = computed(() => page.props.backend.site);
const sites = computed(() => page.props.backend.sites);
const flash = computed(() => page.props.backend.flash?.status ?? null);

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

function switchSite(event: Event): void {
    const id = Number((event.target as HTMLSelectElement).value);
    const target = sites.value.find((s) => s.id === id);
    if (target) {
        router.post(target.switch_url);
    }
}

/* CR-005: Modi nur, wenn die Rolle dort einen Bereich sieht — mit nur einem gibt es nichts umzuschalten */
const modes = computed(() => {
    const list = [
        {
            key: 'content',
            label: t('Inhalte'),
            href: backend.value.content_url,
        },
    ];
    if (backend.value.modes?.setup !== false) {
        list.push({
            key: 'setup',
            label: t('Einrichtung'),
            href: backend.value.setup_url,
        });
    }
    /* TB 23: «Verwaltung» als eigener Modus — Sites, Benutzer, Rollen, Einstellungen und die Seiten der Module */
    if (backend.value.modes?.admin !== false && backend.value.admin_url) {
        list.push({
            key: 'admin',
            label: t('Verwaltung'),
            href: backend.value.admin_url,
        });
    }
    return list.length > 1 ? list : [];
});
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a
            href="#inhalt"
            class="sr-only rounded-md bg-paper px-3 py-2 text-[13px] font-semibold text-basalt focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50"
            >{{ $t('Zum Inhalt springen') }}</a
        >
        <DemoBanner />
        <TrialBanner />
        <AccountExitBanner />
        <SupportBanner />
        <header class="bg-basalt text-paper">
            <div
                class="mx-auto flex min-h-12 max-w-6xl flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-1.5 md:h-12 md:flex-nowrap md:gap-x-3.5 md:py-0"
            >
                <Link
                    :href="backend.content_url"
                    class="text-[17px]"
                    :aria-label="
                        $t(':brand — Übersicht', {
                            brand: page.props.backend.brand ?? 'Basalto',
                        })
                    "
                >
                    <span
                        v-if="page.props.backend.brand"
                        class="font-semibold tracking-tight"
                        >{{ page.props.backend.brand }}</span
                    >
                    <Wordmark v-else tone="basalt" />
                </Link>

                <label
                    v-if="sites.length > 1"
                    class="sr-only"
                    for="site-switch"
                    >{{ $t('Site') }}</label
                >
                <select
                    v-if="sites.length > 1"
                    id="site-switch"
                    :value="site?.id"
                    class="focus:ring-lava-light max-w-[12rem] rounded-md bg-basalt-light px-2.5 py-1 text-xs text-paper focus:ring-2 focus:outline-none"
                    @change="switchSite"
                >
                    <option v-for="s in sites" :key="s.id" :value="s.id">
                        {{ s.name }}
                    </option>
                </select>
                <span
                    v-else-if="site"
                    class="hidden items-center gap-[7px] rounded-md bg-basalt-light px-2.5 py-[5px] text-[12.5px] whitespace-nowrap sm:flex"
                >
                    {{ site.name }}
                    <span class="font-mono text-[10.5px] text-ash-light">{{
                        site.handle
                    }}</span>
                </span>

                <nav
                    v-if="modes.length > 0"
                    class="flex gap-0.5 rounded-lg bg-basalt-light p-[3px]"
                    :aria-label="$t('Modus')"
                >
                    <Link
                        v-for="m in modes"
                        :key="m.key"
                        :href="m.href"
                        class="flex min-h-6 items-center gap-[7px] rounded-md px-2.5 py-1 text-xs leading-tight font-semibold whitespace-nowrap"
                        :class="
                            backend.mode === m.key
                                ? 'bg-paper text-basalt'
                                : 'text-ash-light hover:text-paper'
                        "
                        :aria-current="
                            backend.mode === m.key ? 'page' : undefined
                        "
                    >
                        <span
                            v-if="m.key === 'content'"
                            class="hex inline-block h-2.5 w-[11px]"
                            :class="
                                backend.mode === m.key ? 'bg-basalt' : 'bg-ash'
                            "
                            aria-hidden="true"
                        />
                        <span
                            v-else-if="m.key === 'admin'"
                            class="inline-flex h-2.5 w-[11px] flex-col justify-between"
                            aria-hidden="true"
                        >
                            <span
                                v-for="n in 3"
                                :key="n"
                                class="h-[1.5px] w-full rounded"
                                :class="
                                    backend.mode === m.key
                                        ? 'bg-basalt'
                                        : 'bg-ash'
                                "
                            />
                        </span>
                        <span
                            v-else
                            class="relative inline-block h-0.5 w-4 after:absolute after:top-[-2px] after:left-[5px] after:h-1.5 after:w-1.5 after:rounded-full after:content-['']"
                            :class="
                                backend.mode === m.key
                                    ? 'bg-lava after:bg-lava'
                                    : 'bg-ash after:bg-ash'
                            "
                            aria-hidden="true"
                        />
                        {{ m.label }}
                    </Link>
                </nav>

                <BackendSearch
                    v-if="page.props.backend.search_url"
                    :url="page.props.backend.search_url"
                />

                <!-- TB 27: Glocke und Benutzermenü als Paar rechts — auf dem Telefon schiebt das Paar nach rechts -->
                <div class="ml-auto flex items-center gap-1.5 md:ml-0">
                    <!-- Gegenstück zu «Zum CMS» im Extranet: nur für Superadmin und das Team des Extranets -->
                    <a
                        v-if="backend.extranet_url"
                        :href="backend.extranet_url"
                        class="mr-1.5 hidden text-[11.5px] text-ash-light underline hover:text-paper sm:inline"
                        >{{ $t('Zum Extranet') }}</a
                    >
                    <NotificationBell />
                    <UserMenu
                        v-if="user"
                        :user="user"
                        :profile-url="backend.profile_url"
                        :settings-url="backend.settings_url"
                        :logout-url="backend.logout_url"
                        :help="Boolean(backend.help)"
                    />
                </div>
            </div>
        </header>

        <ModeBar />

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
