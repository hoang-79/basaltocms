<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../components/Button.vue';
import Card from '../../components/Card.vue';
import {
    deliveryWord,
    fileSize,
    submissionStatusTone,
    submissionStatusWord,
} from '../../components/forms';
import PageHeader from '../../components/PageHeader.vue';
import PairRow from '../../components/PairRow.vue';
import StateTag from '../../components/StateTag.vue';
import { t } from '../../i18n';
import type { BackendProps } from '../../types';
import type {
    SubmissionPageProps,
    SubmissionStatus,
    SubmissionValue,
} from '../../types-forms';

/**
 * Ein Eingang (Bereich 16, mobil 16/2): alle Felder als Paare, Dateien zum Herunterladen, Status (neu, gelesen,
 * beantwortet), Zustellung von Mail und Webhook mit Grund und «Mail erneut senden», Antworten per Mail an den
 * Absender, endgültig löschen samt Dateien. Wer den Eingang öffnet, hat ihn gelesen — das setzt der Server.
 */
const props = defineProps<SubmissionPageProps>();

const page = usePage<BackendProps>();
const siteName = computed(
    () => page.props.backend.site?.name ?? page.props.backend.name,
);

const statuses: SubmissionStatus[] = ['new', 'read', 'answered'];

const s = computed(() => props.submission);

/** Lange Werte (Mehrzeilig, über 60 Zeichen) stehen als Absatz, kurze als Paar. */
function isLong(v: SubmissionValue): boolean {
    return (
        v.file === null &&
        (v.type === 'textarea' || v.value.length > 60 || v.value.includes('\n'))
    );
}

const shortValues = computed(() => s.value.values.filter((v) => !isLong(v)));
const longValues = computed(() => s.value.values.filter((v) => isLong(v)));

const sender = computed(
    () =>
        s.value.values.find((v) => v.type === 'text' && v.value !== '')
            ?.value ??
        s.value.reply_to ??
        t('Eingang'),
);

const replyHref = computed(() =>
    s.value.reply_to
        ? `mailto:${s.value.reply_to}?subject=${encodeURIComponent(`Ihre Anfrage · ${props.form.label}`)}`
        : null,
);

const busy = ref(false);

function setStatus(status: SubmissionStatus): void {
    if (status === s.value.status) {
        return;
    }
    router.put(
        props.urls.update,
        { status },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function resend(): void {
    if (props.urls.resend) {
        router.post(props.urls.resend, {}, { preserveScroll: true });
    }
}

const confirmingDelete = ref(false);

function destroy(): void {
    router.delete(props.urls.destroy);
}

function deliveryTone(
    status: string | null,
): 'success' | 'danger' | 'grey' | 'warning' {
    return status === 'sent'
        ? 'success'
        : status === 'failed'
          ? 'danger'
          : status === 'pending'
            ? 'warning'
            : 'grey';
}

const statusButton =
    'rounded-md border px-2.5 py-[5px] text-xs font-semibold transition-colors disabled:cursor-not-allowed';
</script>

<template>
    <Head :title="$t('Eingang · :form', { form: form.label })" />
    <PageHeader
        :title="sender"
        :crumb="
            $t(':site › Formulare › :form › Eingänge', {
                site: siteName,
                form: form.label,
            })
        "
    >
        <template #lead>
            <span class="font-mono">{{ s.created_at }}</span> ·
            {{ $t('Sprache :locale', { locale: s.locale })
            }}<template v-if="s.page">
                · {{ $t('Seite') }}
                <span class="font-mono">{{ s.page }}</span></template
            >
            ·
            {{
                s.consent_at
                    ? $t('Zustimmung :date', { date: s.consent_at })
                    : $t('ohne Zustimmung erfasst')
            }}
        </template>
        <template #actions>
            <a
                v-if="replyHref"
                :href="replyHref"
                class="inline-flex items-center justify-center rounded-md border border-transparent bg-lava-dark px-[13px] py-2 text-[13px] leading-tight font-semibold whitespace-nowrap text-white hover:brightness-90"
                >{{ $t('Antworten') }}</a
            >
            <Button variant="link" :href="urls.inbox">{{
                $t('← Eingänge')
            }}</Button>
        </template>
    </PageHeader>

    <div class="grid items-start gap-3 md:grid-cols-[minmax(0,1fr)_17rem]">
        <Card
            :title="$t('Angaben')"
            :sub="$t('Formular «:form»', { form: form.label })"
        >
            <dl>
                <PairRow
                    v-for="v in shortValues"
                    :key="v.name"
                    :label="v.label"
                >
                    <a
                        v-if="v.file"
                        :href="v.file.url"
                        class="text-lava-dark underline hover:text-basalt"
                        download
                        >{{ v.file.name }}
                        <span class="font-normal text-basalt-light"
                            >· {{ fileSize(v.file.size) }}</span
                        ></a
                    >
                    <span v-else-if="v.value === ''" class="text-ash-dark"
                        >—</span
                    >
                    <template v-else>{{ v.value }}</template>
                </PairRow>
            </dl>
            <div v-for="v in longValues" :key="v.name" class="mt-3">
                <span
                    class="mb-1 block text-[9.5px] font-semibold tracking-[.1em] text-basalt-light uppercase"
                    >{{ v.label }}</span
                >
                <p
                    class="text-[13px] leading-relaxed break-words whitespace-pre-wrap"
                >
                    {{ v.value }}
                </p>
            </div>
        </Card>

        <div class="space-y-3">
            <Card :title="$t('Status')">
                <template #right>
                    <StateTag :tone="submissionStatusTone(s.status)">{{
                        submissionStatusWord(s.status)
                    }}</StateTag>
                </template>
                <div
                    class="flex flex-wrap gap-1.5"
                    role="group"
                    :aria-label="$t('Status')"
                >
                    <button
                        v-for="st in statuses"
                        :key="st"
                        type="button"
                        :class="[
                            statusButton,
                            st === s.status
                                ? 'border-basalt bg-basalt text-paper'
                                : 'border-ash bg-white text-basalt hover:bg-paper',
                        ]"
                        :aria-pressed="st === s.status"
                        :disabled="busy"
                        @click="setStatus(st)"
                    >
                        {{ submissionStatusWord(st) }}
                    </button>
                </div>
            </Card>

            <Card
                :title="$t('Zustellung')"
                :status="
                    s.mail_status === 'failed' || s.webhook_status === 'failed'
                        ? 'danger'
                        : null
                "
            >
                <dl>
                    <PairRow :label="$t('Mail')">
                        <StateTag :tone="deliveryTone(s.mail_status)">{{
                            deliveryWord(s.mail_status)
                        }}</StateTag>
                    </PairRow>
                    <PairRow v-if="s.webhook_status" label="Webhook">
                        <StateTag :tone="deliveryTone(s.webhook_status)">{{
                            deliveryWord(s.webhook_status)
                        }}</StateTag>
                    </PairRow>
                </dl>
                <p
                    v-if="s.mail_status === 'failed' && s.mail_error"
                    class="text-danger mt-1.5 text-[11.5px] font-semibold break-words"
                >
                    {{ $t('Mail: :error', { error: s.mail_error }) }}
                </p>
                <p
                    v-if="s.webhook_status === 'failed' && s.webhook_error"
                    class="text-danger mt-1.5 text-[11.5px] font-semibold break-words"
                >
                    Webhook: {{ s.webhook_error }}
                </p>
                <div v-if="urls.resend" class="mt-2">
                    <Button
                        :variant="
                            s.mail_status === 'failed' ? 'primary' : 'secondary'
                        "
                        size="sm"
                        @click="resend"
                        >{{ $t('Mail erneut senden') }}</Button
                    >
                </div>
            </Card>

            <Card :title="$t('Löschen')">
                <p class="text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Endgültig, samt Dateien — die Löschung steht im Protokoll.',
                        )
                    }}
                </p>
                <div class="mt-2">
                    <Button
                        v-if="!confirmingDelete"
                        variant="danger"
                        size="sm"
                        @click="confirmingDelete = true"
                        >{{ $t('Endgültig löschen') }}</Button
                    >
                    <span
                        v-else
                        class="inline-flex flex-wrap items-center gap-2 text-[12.5px]"
                        role="alertdialog"
                    >
                        {{ $t('Wirklich endgültig löschen?') }}
                        <Button variant="danger" size="sm" @click="destroy">{{
                            $t('Ja, löschen')
                        }}</Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            @click="confirmingDelete = false"
                            >{{ $t('Nein') }}</Button
                        >
                    </span>
                </div>
            </Card>
        </div>
    </div>
</template>
