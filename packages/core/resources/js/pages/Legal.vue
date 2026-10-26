<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Card from '../components/Card.vue';
import PageHeader from '../components/PageHeader.vue';

/**
 * Hilfe › Recht (G-07, G-06; TB 11 Scheibe 3): ein Rechtstext aus `docs/recht/<sprache>/` als HTML (auf dem Server aus
 * Markdown, rohes HTML entfernt) in der Sprache der Oberfläche, links die übrigen Texte, oben die anderen Sprachen und
 * die öffentliche Adresse — dieselbe Seite, die Installer, Module und basaltocms.com verlinken.
 */
defineProps<{
    page: {
        slug: string;
        locale: string;
        title: string;
        html: string;
        draft: boolean;
    };
    texts: { slug: string; title: string; url: string }[];
    languages: { code: string; label: string; url: string; current: boolean }[];
    public_url: string;
}>();
</script>

<template>
    <Head :title="$t('Recht · :title', { title: page.title })" />
    <PageHeader :title="page.title" :crumb="$t('Hilfe · Recht')">
        <template #actions>
            <nav :aria-label="$t('Sprache')">
                <ul class="flex flex-wrap gap-x-3 gap-y-1 text-[12.5px]">
                    <li v-for="language in languages" :key="language.code">
                        <Link
                            :href="language.url"
                            :lang="language.code"
                            :hreflang="language.code"
                            :aria-current="
                                language.current ? 'page' : undefined
                            "
                            :class="
                                language.current
                                    ? 'font-semibold text-basalt'
                                    : 'underline'
                            "
                            >{{ language.label }}</Link
                        >
                    </li>
                    <li>
                        <a
                            :href="public_url"
                            class="underline"
                            target="_blank"
                            rel="noopener"
                            >{{ $t('Öffentliche Adresse') }}</a
                        >
                    </li>
                </ul>
            </nav>
        </template>
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-[14rem_minmax(0,1fr)]">
        <nav :aria-label="$t('Rechtstexte')">
            <ul class="grid gap-0.5 text-[13px]">
                <li v-for="item in texts" :key="item.slug">
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
        </nav>
        <Card>
            <!-- eslint-disable-next-line vue/no-v-html -- Markdown aus dem Repository, auf dem Server ohne rohes HTML gerendert -->
            <article
                class="recht max-w-[75ch] break-words hyphens-auto"
                :lang="page.locale"
                v-html="page.html"
            />
        </Card>
    </div>
</template>

<style scoped>
.recht {
    font-size: 14px;
    line-height: 1.65;
}
.recht :deep(h2) {
    margin: 1.4em 0 0.4em;
    font-size: 17px;
    font-weight: 700;
}
.recht :deep(p),
.recht :deep(ul),
.recht :deep(ol),
.recht :deep(table) {
    margin: 0 0 0.8em;
}
.recht :deep(ul) {
    padding-left: 1.2em;
    list-style: disc;
}
.recht :deep(ol) {
    padding-left: 1.4em;
    list-style: decimal;
}
.recht :deep(li) {
    margin: 0.2em 0;
}
.recht :deep(a) {
    text-decoration: underline;
}
.recht :deep(code) {
    font-family: var(--font-mono);
    font-size: 12.5px;
}
.recht :deep(blockquote) {
    margin: 0 0 1em;
    padding: 6px 12px;
    border-left: 3px solid var(--color-lava);
    background: color-mix(in srgb, var(--color-lava) 8%, transparent);
}
.recht :deep(blockquote p) {
    margin: 0;
}
.recht :deep(table) {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.recht :deep(th),
.recht :deep(td) {
    padding: 4px 8px 4px 0;
    border-bottom: 1px solid var(--color-ash-light);
    text-align: left;
    vertical-align: top;
}
/* Tabellen auf schmalen Schirmen stapeln statt quetschen: je Zeile ein Block */
@media (max-width: 40rem) {
    .recht :deep(table),
    .recht :deep(tbody),
    .recht :deep(tr),
    .recht :deep(td) {
        display: block;
    }
    .recht :deep(thead) {
        display: none;
    }
    .recht :deep(tr) {
        padding: 6px 0;
        border-bottom: 1px solid var(--color-ash-light);
    }
    .recht :deep(td) {
        padding: 0;
        border: 0;
    }
    .recht :deep(td:first-child) {
        font-weight: 600;
    }
}
.recht :deep(hr) {
    margin: 1.4em 0;
    border: 0;
    border-top: 1px solid var(--color-ash-light);
}
</style>
