import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// Eigener Build des Kern-Pakets (Statamic-Muster): Ergebnis liegt im Paket unter resources/dist und wird per
// «php artisan vendor:publish --tag=basalto-assets» nach public/vendor/basalto/core kopiert — Installationen brauchen kein Node.
const root = fileURLToPath(new URL('.', import.meta.url));
const monorepo = fileURLToPath(new URL('../..', import.meta.url));

export default defineConfig({
    root,
    base: '/vendor/basalto/core/build/',
    plugins: [
        laravel({
            // TB 10 Scheibe 4: «frontend-editor.ts» ist die Insel des Frontend-Editors (M-19) — eigenes kleines Bundle
            input: [
                'resources/css/backend.css',
                'resources/js/backend.ts',
                'resources/js/frontend-editor.ts',
                // CR-006 (FO-K4): Inseln der Community (Editor für Besucher …)
                'resources/js/community.ts',
                // CR-008: Live-Aktualisierung — eigenes kleines Bündel, ohne den Editor der Community
                'resources/js/live.ts',
                // TB 53: Passkeys im Kundenkonto (Anmelden, Hinzufügen) — eigenes kleines Bündel
                'resources/js/konto.ts',
                // TB 71: Live-Suche im Forum (Vorschläge in der Leiste, Treffer beim Tippen) — eigenes kleines Bündel
                'resources/js/search.ts',
                'resources/js/codefelder.ts',
            ],
            publicDirectory: 'resources/dist',
            buildDirectory: 'build',
            hotFile: `${root}resources/dist/hot`,
            refresh: false,
        }),
        vue(),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@core': `${root}resources/js` },
    },
    server: {
        fs: { allow: [monorepo] },
    },
    build: {
        emptyOutDir: true,
    },
});
