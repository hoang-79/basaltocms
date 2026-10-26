import { createInertiaApp, router } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { applyTheme } from './components/theme';
import { i18n, setLocale } from './i18n';
import BackendLayout from './layouts/BackendLayout.vue';
import ExtranetLayout from './layouts/ExtranetLayout.vue';

// Seiten des Kerns und aller Module im Monorepo — ein Build, ein Bundle. Namensraum «kern::Seite» → Datei.
const pages = import.meta.glob<{ default: DefineComponent }>([
    './pages/**/*.vue',
    '../../../../modules/*/resources/js/pages/**/*.vue',
]);

function fileFor(name: string): string {
    const [namespace, page] = name.split('::');

    return namespace === 'core'
        ? `./pages/${page}.vue`
        : `../../../../modules/${namespace}/resources/js/pages/${page}.vue`;
}

void createInertiaApp({
    title: (title) => (title ? `${title} · Basalto` : 'Basalto'),
    // Vor jeder Seite die Sprache der Oberfläche (Profil) laden — beim Wechsel im Profil greift sie mit dem nächsten Besuch
    resolve: async (name, page) => {
        const shared = page?.props as
            | { backend?: { locale?: string; appearance?: string } }
            | undefined;
        // TB 21: Darstellung (hell, dunkel, wie das System) nach dem Profil
        applyTheme(
            shared?.backend?.appearance ??
                document.documentElement.dataset.themePref,
        );
        await setLocale(
            shared?.backend?.locale ?? document.documentElement.lang,
        );
        const loader = pages[fileFor(name)];

        if (!loader) {
            throw new Error(`Backend-Seite «${name}» fehlt im Build des Kerns`);
        }

        return (await loader()).default;
    },
    withApp(app) {
        app.use(i18n);
    },
    // TB 19: im Hersteller-Extranet ein eigener Rahmen (Abzeichen, Menü nach Rechten) — auch für die Seiten der Module
    layout: (_name, page) =>
        (page?.props as { extranet?: unknown } | undefined)?.extranet
            ? ExtranetLayout
            : BackendLayout,
    progress: { color: '#e4572e' },
});

// TB 21: ein Wechsel der Darstellung im Profil wirkt sofort, auch ohne neue Seite
router.on('navigate', (event) => {
    const props = event.detail.page.props as {
        backend?: { appearance?: string };
    };
    if (props.backend?.appearance) {
        applyTheme(props.backend.appearance);
    }
});
