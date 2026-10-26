<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Help\LegalTexts;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rechtstexte (G-07, G-06; TB 11 Scheibe 3): im Backend für alle Angemeldeten in der Sprache der Oberfläche (andere
 * Sprache über `?sprache=`), öffentlich als eigenständige HTML-Seite ohne Sitzung, Datenbank und Skript — so ist der
 * Lizenzvertrag schon im Installer lesbar, bevor es eine Datenbank gibt.
 */
final class LegalController
{
    private const LANGUAGES = ['de' => 'Deutsch', 'en' => 'English', 'it' => 'Italiano', 'fr' => 'Français'];

    public function backend(Request $request, string $text = 'lizenzvertrag'): Response
    {
        $interface = LegalTexts::locale();
        $wanted = $request->query('sprache');
        $locale = is_string($wanted) && in_array($wanted, LegalTexts::LOCALES, true) ? $wanted : $interface;
        abort_unless(LegalTexts::exists($locale, $text), 404);

        $url = static fn (string $slug, string $lang): string => route('basalto.legal', ['text' => $slug] + ($lang === $interface ? [] : ['sprache' => $lang]));

        return Inertia::render('core::Legal', [
            'page' => LegalTexts::render($locale, $text, $url),
            'texts' => array_map(static fn (array $t): array => $t + ['url' => $url($t['slug'], $locale)], LegalTexts::index($locale)),
            'languages' => $this->languages($text, $locale, $url),
            'public_url' => LegalTexts::publicUrl($text, $locale),
        ]);
    }

    public function show(string $locale, string $text): HttpResponse
    {
        abort_unless(LegalTexts::exists($locale, $text), 404);

        $url = static fn (string $slug, string $lang): string => LegalTexts::publicUrl($slug, $lang);
        $page = LegalTexts::render($locale, $text, $url);

        return response()->view('basalto::legal', [
            'page' => $page,
            'texts' => array_map(static fn (array $t): array => $t + ['url' => $url($t['slug'], $locale)], LegalTexts::index($locale)),
            'languages' => $this->languages($text, $locale, $url),
            'labels' => ['texts' => __('Rechtstexte', [], $locale), 'language' => __('Sprache', [], $locale)],
        ])->header('Content-Language', $locale)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * @param  \Closure(string, string): string  $url
     * @return list<array{code: string, label: string, url: string, current: bool}>
     */
    private function languages(string $text, string $locale, \Closure $url): array
    {
        return array_map(static fn (string $code): array => ['code' => $code, 'label' => self::LANGUAGES[$code], 'url' => $url($text, $code), 'current' => $code === $locale],
            LegalTexts::locales($text));
    }
}
