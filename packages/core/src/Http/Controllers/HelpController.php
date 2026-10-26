<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Help\HelpPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hilfe (N-09): eine Seite aus `docs/` mit der Liste aller Seiten nach Bereichen — für alle Angemeldeten; in der
 * Sprache der Oberfläche, sofern es die Seite darin gibt (TB 11 Scheibe 5, `docs/en/`).
 *
 * Webhilfe (TB 20): `panel` und `search` liefern dasselbe als JSON für das Hilfe-Panel, das im Backend über jeder
 * Seite aufgeht («?» im Seitenkopf, Taste ? oder F1).
 */
final class HelpController
{
    public function __invoke(string $page = 'index'): Response
    {
        abort_unless(HelpPages::exists($page), 404);

        return Inertia::render('core::Help', [
            'page' => HelpPages::render($page) + ['section' => HelpPages::sectionLabel(HelpPages::locate($page)[0])],
            'sections' => self::sections(),
        ]);
    }

    public function panel(string $page = 'index'): JsonResponse
    {
        abort_unless(HelpPages::exists($page), 404);

        return response()->json([
            'page' => HelpPages::render($page) + [
                'section' => HelpPages::sectionLabel(HelpPages::locate($page)[0]),
                'url' => route('basalto.help', ['page' => $page]),
            ],
            'sections' => self::sections(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json(['results' => array_map(static fn (array $hit): array => $hit + [
            'url' => route('basalto.help', ['page' => $hit['slug']]).($hit['anchor'] === null ? '' : '#'.$hit['anchor']),
        ], HelpPages::search($query))]);
    }

    /** @return list<array{key: string, label: string, pages: list<array{slug: string, title: string, url: string}>}> */
    private static function sections(): array
    {
        return array_map(static fn (array $section): array => [...$section, 'pages' => array_map(
            static fn (array $p): array => $p + ['url' => route('basalto.help', ['page' => $p['slug']])],
            $section['pages'],
        )], HelpPages::sections());
    }
}
