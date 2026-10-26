<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import CodeInput from '../components/CodeInput.vue';
import Notice from '../components/Notice.vue';
import ResendCode from '../components/ResendCode.vue';
import GuestLayout from '../layouts/GuestLayout.vue';

defineOptions({ layout: GuestLayout });

/**
 * Code eingeben (Bereich 8): je Ziffer ein Feld (TB 72) — Einfügen oder das Ausfüllen durch den Browser verteilt den
 * Code, sind alle Ziffern da, sendet die Seite von selbst. «Code erneut senden» erst nach der Wartezeit aus den
 * Einstellungen; fünf Fehlversuche sperren den Code.
 */
const props = defineProps<{
    email: string;
    verifyUrl: string;
    backUrl: string;
    status: string | null;
    resend: { url: string; wait: number; seconds: number };
}>();

const form = useForm({ code: '' });

function submit(): void {
    if (form.processing || form.code.length !== 6) {
        return;
    }
    // Falscher Code: Felder leeren, damit der nächste Versuch wieder vorne beginnt
    form.post(props.verifyUrl, { onError: () => form.reset('code') });
}
</script>

<template>
    <Head :title="$t('Code eingeben')" />
    <h1 class="mb-1.5 text-sm font-semibold">{{ $t('Code eingeben') }}</h1>
    <Notice v-if="status" tone="success" class="mb-3">{{ status }}</Notice>
    <p class="text-[12.5px] text-basalt-light">
        {{ $t('Der Code ging an') }}
        <strong class="font-semibold text-basalt">{{ email }}</strong
        >.
    </p>
    <form class="mt-4" novalidate @submit.prevent="submit">
        <p
            id="code-label"
            class="mb-2 text-center text-[12.5px] font-medium text-basalt"
        >
            {{ $t('Sechsstelliger Code') }}
        </p>
        <CodeInput
            id="code"
            v-model="form.code"
            :invalid="!!form.errors.code"
            :disabled="form.processing"
            describedby="code-label code-help"
            @complete="submit"
        />
        <p
            v-if="form.errors.code"
            class="text-danger mt-2 text-center text-xs"
            role="alert"
        >
            {{ form.errors.code }}
        </p>
        <p
            id="code-help"
            class="mt-2 text-center text-[11.5px] text-basalt-light"
        >
            {{
                form.processing
                    ? $t('Wird geprüft …')
                    : $t('Zehn Minuten gültig, fünf Versuche.')
            }}
        </p>
    </form>
    <div class="mt-4 text-center text-xs">
        <ResendCode
            :url="resend.url"
            :wait="resend.wait"
            :seconds="resend.seconds"
            class="text-basalt-light hover:text-basalt"
        />
        <p
            v-if="$page.props.errors?.resend"
            class="text-danger mt-1"
            role="alert"
        >
            {{ $page.props.errors.resend }}
        </p>
        <p class="mt-2">
            <Link
                :href="backUrl"
                class="text-basalt-light underline hover:text-basalt"
                >{{ $t('Andere Adresse') }}</Link
            >
        </p>
    </div>
</template>
