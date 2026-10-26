<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '../components/Button.vue';
import { controlClasses } from '../components/control';
import Field from '../components/Field.vue';
import Notice from '../components/Notice.vue';
import GuestLayout from '../layouts/GuestLayout.vue';

defineOptions({ layout: GuestLayout });

/** Code eingeben (Bereich 8): Plex Mono, Eingabe per one-time-code aus der Mail; fünf Fehlversuche sperren den Code. */
defineProps<{
    email: string;
    verifyUrl: string;
    backUrl: string;
    status: string | null;
}>();

const form = useForm({ code: '' });
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
    <form class="mt-3" novalidate @submit.prevent="form.post(verifyUrl)">
        <Field
            :label="$t('Sechsstelliger Code')"
            :error="form.errors.code"
            :help="$t('Zehn Minuten gültig, fünf Versuche.')"
        >
            <template #default="{ id, invalid, describedby }">
                <input
                    :id="id"
                    :aria-describedby="describedby"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    required
                    :class="[
                        controlClasses({ error: invalid, mono: true }),
                        'h-11 text-center text-lg font-medium tracking-[.5em]',
                    ]"
                    :aria-invalid="invalid ? 'true' : undefined"
                />
            </template>
        </Field>
        <div class="mt-3">
            <Button type="submit" wide :disabled="form.processing">{{
                $t('Anmelden')
            }}</Button>
        </div>
    </form>
    <p class="mt-3 text-center text-xs">
        <Link
            :href="backUrl"
            class="text-basalt-light underline hover:text-basalt"
            >{{ $t('Andere Adresse') }}</Link
        >
    </p>
</template>
