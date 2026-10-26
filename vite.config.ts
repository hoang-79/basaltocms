import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            // Lava-Watcher (L-13): eine Änderung an theme.lava oder einem Markup-Teil lädt den Browser neu;
            // der Baum-Cache kompiliert anhand des Fingerabdrucks der Quelle neu.
            refresh: [
                'resources/views/**',
                'routes/**',
                'themes/**/*.lava',
                'themes/**/*.lava.html',
            ],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['packages/core/resources/js/**/*.test.ts'],
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            'packages/*/resources/dist/**',
            // Eigene Abhängigkeiten (vscode-languageclient) nur in ihrem Ordner installiert — typbewusst geprüft
            // wird sie dort mit tsc; in der Pipeline fehlen die Typen, und der Linter hielte alles für «any»
            'tools/vscode-lava/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'dokumentationen/**',
            // Produkt-Doku wie die Projektdoku: Markdown von Hand gesetzt (Tabellen, Umbrüche)
            'docs/**',
            // Lava-Teile sind Markup mit {% … %} — der HTML-Formatierer würde sie umbrechen
            'themes/**/*.lava.html',
            'packages/*/resources/dist/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
