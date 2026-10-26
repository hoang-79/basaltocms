<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { computed, ref } from 'vue';
import { t } from '../i18n';
import Button from '../components/Button.vue';
import Card from '../components/Card.vue';
import Field from '../components/Field.vue';
import Notice from '../components/Notice.vue';
import PageHeader from '../components/PageHeader.vue';

const props = defineProps<{
    passkeys: {
        id: number;
        name: string;
        created_at: string | null;
        last_used_at: string | null;
    }[];
    routes: { options: string; store: string; profile?: string };
}>();

/** Der Weg zurück ins Profil — aus dem Vertrag, sonst die feste Adresse des Kerns. */
const profileUrl = computed(() => props.routes.profile ?? '/admin/profil');

const name = ref(
    [/Edg|Edge/, /Firefox/, /Chrome/, /Safari/]
        .find((p) => p.test(navigator.userAgent))
        ?.source.replace(/\W/g, '') ?? t('Gerät'),
);

const { register, isLoading, error, isSupported } = usePasskeyRegister({
    routes: { options: props.routes.options, submit: props.routes.store },
    onSuccess: () => router.reload(),
});

async function submit(): Promise<void> {
    if (name.value.trim() !== '') {
        await register(name.value.trim());
    }
}
</script>

<template>
    <Head :title="$t('Passkeys')" />
    <PageHeader
        :title="$t('Passkeys')"
        :lead="
            $t(
                'Ein Passkey ersetzt Passwort und Mail-Code: Anmelden mit Touch ID, Windows Hello oder dem Schlüssel Ihres Geräts.',
            )
        "
    >
        <template #crumb>
            <Link :href="profileUrl" class="underline hover:text-basalt">{{
                $t('Konto')
            }}</Link>
            › {{ $t('Passkeys') }}
        </template>
        <template #actions>
            <Button variant="secondary" :href="profileUrl">{{
                $t('Profil')
            }}</Button>
        </template>
    </PageHeader>
    <div class="grid gap-4 md:grid-cols-2">
        <Card :title="$t('Neuer Passkey')">
            <p v-if="!isSupported" class="text-[12.5px] text-basalt-light">
                {{ $t('Dieser Browser unterstützt keine Passkeys.') }}
            </p>
            <form v-else @submit.prevent="submit">
                <Field
                    v-model="name"
                    :label="$t('Bezeichnung')"
                    required
                    :help="
                        $t('Vorbelegt mit dem Browser — Sie können es ändern.')
                    "
                    :error="error ?? ''"
                />
                <div class="mt-3">
                    <Button type="submit" :disabled="isLoading">
                        {{
                            isLoading
                                ? $t('Wird registriert …')
                                : $t('Passkey registrieren')
                        }}
                    </Button>
                </div>
                <p class="mt-2.5 text-[11.5px] text-basalt-light">
                    {{
                        $t(
                            'Ihr Gerät fragt gleich nach Touch ID oder PIN. Abbrechen ist jederzeit möglich.',
                        )
                    }}
                </p>
            </form>
        </Card>
        <Card
            :title="$t('Registrierte Passkeys')"
            :sub="$tc(':count Passkey|:count Passkeys', passkeys.length)"
        >
            <p
                v-if="passkeys.length === 0"
                class="text-[12.5px] text-basalt-light"
            >
                {{ $t('Noch keiner.') }}
            </p>
            <ul v-else class="divide-y divide-ash-light text-[12.5px]">
                <li
                    v-for="p in passkeys"
                    :key="p.id"
                    class="py-2 first:pt-0 last:pb-0"
                >
                    <span class="font-semibold">{{ p.name }}</span>
                    <span class="block text-[11.5px] text-basalt-light">{{
                        $t('seit :date · zuletzt :last', {
                            date: p.created_at ?? '—',
                            last: p.last_used_at ?? '—',
                        })
                    }}</span>
                </li>
            </ul>
        </Card>
    </div>
    <Notice tone="grey" class="mt-4">
        {{
            $t(
                'Ohne Passkey bleibt der Hinweis «Passkey einrichten» in der Übersicht stehen. Mit Passkey entfällt der Mail-Code — auch auf Installationen ohne Mail-Server.',
            )
        }}
    </Notice>
</template>
