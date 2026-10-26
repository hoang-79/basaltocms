<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import Notice from '../../components/Notice.vue';
import PageHeader from '../../components/PageHeader.vue';

/**
 * TB 24: «Mit meiner Site anprobieren» — aus dem Katalog von basaltocms.com hierher. Ein Klick holt das Theme
 * (signiert) in die Theme-Bibliothek der Site und zeigt die Site damit in der eigenen Sitzung; gewählt wird es erst
 * unter Einrichtung › Themes. Ohne Bild vom Marktplatz (keine Fremdressource im Backend).
 */
const props = defineProps<{
    name: string;
    entry: {
        title: string;
        version: string;
        author: string;
        free: boolean;
        summary: string | null;
        badges: { label: string; ok: boolean | null }[];
    } | null;
    error: string | null;
    site: { name: string };
    urls: { start: string; modules: string; themes: string };
}>();

const page = usePage();
const pageError = computed(
    () =>
        (page.props.errors as Record<string, string> | undefined)
            ?.marketplace ?? '',
);
const form = useForm({ name: props.name, version: props.entry?.version ?? '' });
</script>

<template>
    <Head :title="$t('Anprobe')" />
    <PageHeader
        :title="$t('Anprobe')"
        :crumb="$t('Verwaltung › Module › Marktplatz')"
    >
        <template #lead>
            {{
                $t(
                    'Ein Theme aus dem Marktplatz mit den Inhalten von «:site» ansehen — nur in Ihrer Sitzung. Die Site bleibt unverändert, bis Sie das Theme unter «Themes» wählen.',
                    { site: site.name },
                )
            }}
        </template>
    </PageHeader>

    <Notice v-if="error" tone="danger" class="mb-4">{{ error }}</Notice>
    <Notice v-if="pageError" tone="danger" class="mb-4" role="alert">{{
        pageError
    }}</Notice>

    <Card v-if="entry" :title="entry.title" class="max-w-xl">
        <p class="mb-1 text-[12.5px] text-basalt-light">
            {{ entry.author }} · v{{ entry.version }}
        </p>
        <p v-if="entry.summary" class="mb-3 text-sm">{{ entry.summary }}</p>
        <ul
            v-if="entry.badges.length > 0"
            class="mb-4 flex flex-wrap gap-x-3 gap-y-0.5 text-[12px]"
            :aria-label="$t('Prüfstand')"
        >
            <li
                v-for="b in entry.badges"
                :key="b.label"
                :class="
                    b.ok === true
                        ? 'text-success'
                        : b.ok === false
                          ? 'text-danger'
                          : 'text-basalt-light'
                "
            >
                <span aria-hidden="true">{{
                    b.ok === true ? '✓' : b.ok === false ? '✗' : '○'
                }}</span>
                {{ b.label }}
            </li>
        </ul>
        <Notice v-if="!entry.free" class="mb-3">
            {{
                $t(
                    'Bezahltes Theme: Die Anprobe braucht einen Kauf mit dem Kundenkonto dieser Installation.',
                )
            }}
        </Notice>
        <div class="flex flex-wrap gap-3">
            <Button
                :disabled="form.processing"
                @click="form.post(urls.start)"
                >{{
                    form.processing
                        ? $t('Wird geholt …')
                        : $t('Mit meiner Site anprobieren')
                }}</Button
            >
            <Button variant="secondary" :href="urls.modules">{{
                $t('Abbrechen')
            }}</Button>
        </div>
    </Card>
    <Notice v-else-if="!error" tone="warning">
        {{ $t('Theme «:name» gibt es im Marktplatz nicht (mehr).', { name }) }}
    </Notice>
</template>
