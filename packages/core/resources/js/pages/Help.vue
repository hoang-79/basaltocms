<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Card from '../components/Card.vue';
import HelpImageViewer from '../components/HelpImageViewer.vue';
import { helpImage } from '../components/help';
import type { HelpImage } from '../components/help';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';
import '../components/help-prose.css';
import { locale, t } from '../i18n';

/**
 * Hilfe › Redaktion (N-09): eine Seite aus `docs/redaktion/` als HTML (auf dem Server aus Markdown, rohes HTML
 * entfernt), links die Liste aller Hilfeseiten. Dieselben Texte rendert die Doku-Site auf basaltocms.com (TB 11).
 * Englisch kommt aus `docs/en/redaktion/` (TB 11 Scheibe 5); fehlt eine Seite in der Sprache der Oberfläche, zeigt
 * der Server den Rückfall (IT/FR → Englisch → Deutsch) und `page.locale` nennt dessen Sprache — dann ein Hinweis.
 * TB 20 (Webhilfe): alle Bereiche der Doku — Redaktion, Betrieb, Entwickler, Marktplatz — in der Navigation.
 */
const props = defineProps<{
    page: {
        slug: string;
        title: string;
        html: string;
        locale?: string;
        section: string;
    };
    sections: {
        key: string;
        label: string;
        pages: { slug: string; title: string; url: string }[];
    }[];
}>();

/* TB 29: Ein Klick auf ein Bild zeigt es gross im Modal statt in einem neuen Tab */
const enlarged = ref<HelpImage | null>(null);
function onArticleClick(event: MouseEvent): void {
    if (
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }
    const image = helpImage(event.target);
    if (image) {
        event.preventDefault();
        enlarged.value = image;
    }
}

const fallbackNotice = computed(() => {
    const shown = props.page.locale ?? 'de';
    if (shown === locale()) {
        return '';
    }
    const notices: Record<string, string> = {
        en: t(
            'Diese Hilfeseite gibt es nicht in Ihrer Sprache. Sie sehen sie auf Englisch.',
        ),
        de: t(
            'Diese Hilfeseite gibt es nicht in Ihrer Sprache. Sie sehen sie auf Deutsch.',
        ),
    };

    return notices[shown] ?? '';
});
</script>

<template>
    <Head :title="$t('Hilfe · :title', { title: page.title })" />
    <PageHeader
        :title="page.title"
        :crumb="$t('Hilfe · :section', { section: page.section })"
    />

    <div class="grid gap-4 md:grid-cols-[14rem_minmax(0,1fr)]">
        <nav
            :aria-label="$t('Hilfeseiten')"
            class="order-last grid content-start gap-3 md:order-none"
        >
            <div v-for="section in sections" :key="section.key">
                <p
                    class="mb-1 px-2.5 text-[11px] font-semibold tracking-wide text-basalt-light uppercase"
                >
                    {{ section.label }}
                </p>
                <ul class="grid gap-0.5 text-[13px]">
                    <li v-for="item in section.pages" :key="item.slug">
                        <Link
                            :href="item.url"
                            class="block rounded-md px-2.5 py-1.5"
                            :class="
                                item.slug === page.slug
                                    ? 'bg-white font-semibold text-basalt shadow-[inset_3px_0_0_var(--color-lava)]'
                                    : 'text-basalt-light hover:bg-white'
                            "
                            :aria-current="
                                item.slug === page.slug ? 'page' : undefined
                            "
                            >{{ item.title }}</Link
                        >
                    </li>
                </ul>
            </div>
        </nav>
        <Card>
            <Notice
                v-if="fallbackNotice"
                tone="grey"
                class="mb-4 max-w-[70ch]"
                >{{ fallbackNotice }}</Notice
            >
            <!-- eslint-disable-next-line vue/no-v-html -- Markdown aus dem Repository, auf dem Server ohne rohes HTML gerendert -->
            <article
                class="hilfe max-w-[70ch]"
                :lang="page.locale ?? 'de'"
                @click="onArticleClick"
                v-html="page.html"
            />
            <HelpImageViewer :image="enlarged" @close="enlarged = null" />
        </Card>
    </div>
</template>
