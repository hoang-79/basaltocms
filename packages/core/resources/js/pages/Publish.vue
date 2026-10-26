<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusHex from '../components/StatusHex.vue';
import { t } from '../i18n';

/**
 * Station Z (L-06): derselbe Lauf wie `basalto:publish` — der Probelauf mit jedem Befund und seinem Ziel; «Live
 * schalten» spielt den Seed ein, prüft, wärmt den Seiten-Cache vor und schaltet die Site live.
 */
type Finding = {
    severity: 'error' | 'warning';
    area: string;
    message: string;
    url: string | null;
};

const props = defineProps<{
    site: {
        name: string;
        handle: string;
        live: boolean;
        live_at: string | null;
    };
    report: {
        findings: Finding[];
        counted: Record<string, number>;
        checks: string[];
        errors: number;
        warnings: number;
    };
    command: string;
    legal: { kind: string; label: string; url: string }[];
    urls: { publish: string; setup: string };
}>();

/** Der Bereich, wie ihn der Lauf in den Befunden nennt (deutsch), und seine Beschriftung in der Sprache der Oberfläche. */
const areas: Record<string, string> = {
    line: 'Rote Linie',
    legal: 'Rechtsseiten',
    translations: 'Pflichtsprachen',
    links: 'Links',
    images: 'Bilder',
    'alt-texts': 'Alt-Texte',
    forms: 'Formulare',
    redirects: 'Umleitungen',
};

const rows = computed(() => {
    const labels: Record<string, string> = {
        line: t('Rote Linie'),
        legal: t('Rechtsseiten'),
        translations: t('Pflichtsprachen'),
        links: t('Links'),
        images: t('Bilder'),
        'alt-texts': t('Alt-Texte'),
        forms: t('Formulare'),
        redirects: t('Umleitungen'),
    };

    return props.report.checks.map((check) => {
        const area = areas[check] ?? check;
        const label = labels[check] ?? check;
        const found = props.report.findings.filter(
            (f) => f.area === area || f.area === label,
        );
        const errors = found.filter((f) => f.severity === 'error').length;

        return {
            check,
            area: label,
            errors,
            warnings: found.length - errors,
            counted: props.report.counted[check] ?? 0,
        };
    });
});

const busy = ref(false);
function applyTemplate(url: string, label: string): void {
    if (
        window.confirm(
            t(
                ':label aus der Vorlage als Entwurf in jeder Sprache anlegen? Ein bestehender Entwurf wird ersetzt, die veröffentlichte Fassung bleibt.',
                { label },
            ),
        )
    ) {
        router.post(url);
    }
}

function publish(): void {
    busy.value = true;
    router.post(
        props.urls.publish,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <Head :title="$t('Veröffentlichen')" />
    <div>
        <PageHeader
            :title="$t('Veröffentlichen')"
            :crumb="$t(':site · Einrichtung · Station Z', { site: site.name })"
        >
            <template #lead>
                <template v-if="site.live">
                    {{
                        $t(
                            'Die Site ist live seit :date. Ein neuer Lauf spielt fehlende Startinhalte ein und wärmt den Seiten-Cache vor.',
                            { date: site.live_at ?? '' },
                        )
                    }}
                </template>
                <template v-else>
                    {{
                        $t(
                            'Die Site ist noch nicht live — Gäste sehen «Bald online». Live geht sie, sobald dieser Lauf ohne Fehler ist.',
                        )
                    }}
                </template>
                {{ $t('Derselbe Lauf wie') }}
                <code class="font-mono">{{ command }}</code
                >.
            </template>
            <template #actions>
                <Button variant="secondary" :href="urls.setup">{{
                    $t('Zur Einrichtung')
                }}</Button>
                <Button
                    :disabled="report.errors > 0 || busy"
                    :title="
                        report.errors > 0
                            ? $t(
                                  'Erst die Fehler beheben — die Liste zeigt, wo.',
                              )
                            : undefined
                    "
                    @click="publish"
                    >{{
                        site.live
                            ? $t('Erneut veröffentlichen')
                            : $t('Live schalten')
                    }}</Button
                >
            </template>
        </PageHeader>

        <div class="grid gap-4 md:grid-cols-[1fr_1.4fr] md:items-start">
            <Card
                :title="$t('Prüfungen')"
                :status="
                    report.errors > 0
                        ? 'danger'
                        : report.warnings > 0
                          ? 'warning'
                          : 'success'
                "
            >
                <template #sub>
                    {{ $tc(':count Fehler|:count Fehler', report.errors) }} ·
                    {{
                        $tc(':count Warnung|:count Warnungen', report.warnings)
                    }}
                </template>
                <ul class="divide-y divide-ash-light">
                    <li
                        v-for="row in rows"
                        :key="row.check"
                        class="flex items-center gap-2 py-1.5 text-[12.5px]"
                    >
                        <StatusHex
                            :status="
                                row.errors > 0
                                    ? 'danger'
                                    : row.warnings > 0
                                      ? 'warning'
                                      : 'success'
                            "
                            size="sm"
                        />
                        <span class="flex-1 font-semibold">{{ row.area }}</span>
                        <span
                            :class="
                                row.errors > 0
                                    ? 'text-danger font-semibold'
                                    : 'text-basalt-light'
                            "
                            >{{
                                row.errors > 0
                                    ? $tc(
                                          ':count Fehler|:count Fehler',
                                          row.errors,
                                      )
                                    : $t(':count geprüft', {
                                          count: row.counted,
                                      })
                            }}</span
                        >
                    </li>
                </ul>
            </Card>

            <Card
                :title="$t('Rechtsseiten aus Vorlage')"
                class="md:col-start-1"
            >
                <template #sub>
                    {{
                        $t(
                            'Nach DSG und DSGVO, in jeder Sprache der Site — als Entwurf.',
                        )
                    }}
                </template>
                <p class="mb-2 text-[12px] text-basalt-light">
                    {{
                        $t(
                            'Bekanntes setzt Basalto ein (Firma, Website, E-Mail, Adresse, Aufbewahrung, Datum). Offene Platzhalter',
                        )
                    }}
                    <code class="font-mono">[[…]]</code>
                    {{
                        $t(
                            'füllen Sie aus — erst dann geht die Site live. Die Vorlage ersetzt keine rechtliche Prüfung im Einzelfall.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="item in legal"
                        :key="item.kind"
                        variant="secondary"
                        size="sm"
                        @click="applyTemplate(item.url, item.label)"
                        >{{
                            $t(':label einsetzen', { label: item.label })
                        }}</Button
                    >
                </div>
            </Card>

            <Card
                :title="$t('Befunde')"
                class="md:col-start-2 md:row-span-2 md:row-start-1"
                :status="report.findings.length === 0 ? 'success' : null"
            >
                <template #sub>
                    {{ $t('Fehler halten die Site offline, Warnungen nicht.') }}
                </template>
                <p
                    v-if="report.findings.length === 0"
                    class="text-success text-[12.5px]"
                >
                    {{ $t('Keine Befunde — bereit zum Veröffentlichen.') }}
                </p>
                <ul v-else class="divide-y divide-ash-light">
                    <li
                        v-for="(finding, i) in report.findings"
                        :key="i"
                        class="flex flex-wrap items-start gap-x-2 gap-y-1 py-2 text-[12.5px]"
                    >
                        <StatusHex
                            :status="
                                finding.severity === 'error'
                                    ? 'danger'
                                    : 'warning'
                            "
                            size="sm"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ finding.area }}</p>
                            <p class="break-words text-basalt">
                                {{ finding.message }}
                            </p>
                        </div>
                        <Link
                            v-if="finding.url"
                            :href="finding.url"
                            class="font-semibold text-lava-dark hover:underline"
                            >{{ $t('Öffnen →') }}</Link
                        >
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
