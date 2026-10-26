<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeAssets;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Printer;
use Basalto\Lava\Theme\Design;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station B — Design (M-15, L-10): Farben, Schriften und Logo des Themes mit den Überschreibungen dieser Site. Speichern
 * wirkt sofort und ohne Build (CSS-Variablen im Kopf jeder Seite); der Kontrast jedes Farbpaars wird gerechnet, ein Paar
 * unter 4,5:1 lässt sich nicht speichern (N-02). Der Lava-Code daneben zeigt, was im Theme steht.
 */
final class DesignController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly ThemeAssets $assets, private readonly Recorder $recorder) {}

    public function show(): Response
    {
        $site = $this->current->require();
        $theme = $this->themes->forSite($site);
        $base = Design::of($theme);
        $effective = $this->assets->design($site);
        $overrides = ThemeAssets::overrides($site);
        $logoMedia = isset($overrides['logo_media_id']) ? Media::query()->find($overrides['logo_media_id']) : null;
        $definition = $theme->document->find('design');

        return Inertia::render('core::Design', [
            'colors' => array_map(static fn (string $name): array => [
                'name' => $name, 'theme' => $base->colors[$name], 'value' => $effective->colors[$name], 'overridden' => isset($overrides['colors'][$name]),
            ], array_map('strval', array_keys($base->colors))),
            'fonts' => array_map(static fn (string $name): array => [
                'name' => $name, 'theme' => $base->fonts[$name]['family'], 'value' => $effective->fonts[$name]['family'], 'weight' => $base->fonts[$name]['weight'],
                'overridden' => isset($overrides['fonts'][$name]),
            ], array_map('strval', array_keys($base->fonts))),
            'pairs' => array_map(static fn (array $pair): array => ['foreground' => $pair[0], 'background' => $pair[1]], $base->contrastPairs()),
            'min_contrast' => Design::MIN_CONTRAST,
            'space' => $base->space,
            'radius' => $base->radius,
            'styles' => array_map(fn (string $path): array => ['path' => $path, 'url' => $this->assets->url($site, $path)], $base->styles),
            'logo' => [
                'theme' => $base->logo !== null && str_starts_with($base->logo, 'assets/') ? $this->assets->url($site, $base->logo) : null,
                'media' => $logoMedia === null ? null : ['id' => $logoMedia->id, 'name' => $logoMedia->name, 'url' => $logoMedia->url()],
            ],
            'images' => Media::query()->where('mime', 'like', 'image/%')->orderByDesc('id')->limit(60)->get(['id', 'name', 'mime', 'path', 'disk', 'variants'])
                ->map(static fn (Media $m): array => ['id' => $m->id, 'name' => $m->name, 'url' => $m->url('thumb')])->all(),
            'lava' => $definition === null ? '' : (new Printer)->printDefinition($definition, 0, true),
            'file' => $definition?->file,
            'tailwind' => $base->tailwind(),
            'can_edit' => Gate::allows('update', $site),
            'urls' => ['update' => route('basalto.setup.design.update'), 'reset' => route('basalto.setup.design.reset'), 'variants' => route('basalto.setup.variants.index')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $base = Design::of($this->themes->forSite($site));
        $data = $request->validate([
            'colors' => ['array'],
            'colors.*' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'fonts' => ['array'],
            'fonts.*' => ['nullable', 'string', 'max:80', 'regex:/^[\pL\pN _-]+$/u'],
            'logo_media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('site_id', $site->id)->whereNull('deleted_at')],
        ], [
            'colors.*.regex' => __('Farbe als #rgb oder #rrggbb.'),
            'fonts.*.regex' => __('Nur Buchstaben, Ziffern, Leerzeichen, Binde- und Unterstrich.'),
        ]);
        $colors = [];
        foreach ($data['colors'] ?? [] as $name => $value) {
            if (isset($base->colors[$name]) && is_string($value) && $value !== '' && strtolower($value) !== $base->colors[$name]) {
                $colors[(string) $name] = strtolower($value);
            }
        }
        $fonts = [];
        foreach ($data['fonts'] ?? [] as $name => $family) {
            $family = is_string($family) ? Design::cleanFamily($family) : '';
            if (isset($base->fonts[$name]) && $family !== '' && $family !== $base->fonts[$name]['family']) {
                $fonts[(string) $name] = $family;
            }
        }
        $errors = [];
        // Dezimalkomma ausser auf Englisch (N-01)
        $decimal = app()->getLocale() === 'en' ? '.' : ',';
        foreach ($base->with(['colors' => $colors])->contrastIssues() as $issue) {
            $field = isset($colors[$issue['foreground']]) ? $issue['foreground'] : $issue['background'];
            $errors['colors.'.$field] = __('Kontrast «:foreground» auf «:background» wäre :ratio:1 — mindestens :required:1 (WCAG 2.2 AA).', ['foreground' => $issue['foreground'], 'background' => $issue['background'], 'ratio' => number_format($issue['ratio'], 2, $decimal, ''), 'required' => number_format($issue['required'], 1, $decimal, '')]);
        }
        if ($errors !== [] && array_intersect_key($errors, array_flip(array_map(static fn (string $n): string => 'colors.'.$n, array_keys($colors)))) !== []) {
            return back()->withErrors($errors)->withInput();
        }
        $logo = isset($data['logo_media_id']) ? (int) $data['logo_media_id'] : null;
        if ($logo !== null && ! str_starts_with((string) Media::query()->whereKey($logo)->value('mime'), 'image/')) {
            return back()->withErrors(['logo_media_id' => __('Das Logo muss ein Bild sein.')]);
        }
        $this->save($site, ['colors' => $colors, 'fonts' => $fonts, 'logo_media_id' => $logo]);

        return back()->with('status', __('Design gespeichert — die Site zeigt es sofort.'));
    }

    public function reset(): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $this->save($site, []);

        return back()->with('status', __('Überschreibungen entfernt — es gilt wieder das Theme.'));
    }

    /** @param  array{colors?: array<string, string>, fonts?: array<string, string>, logo_media_id?: int|null}  $design */
    private function save(Site $site, array $design): void
    {
        $before = ThemeAssets::overrides($site);
        $design = array_filter($design, static fn (mixed $v): bool => $v !== [] && $v !== null);
        $settings = $site->settings ?? [];
        if ($design === []) {
            unset($settings['design']);
        } else {
            $settings['design'] = $design;
        }
        $site->forceFill(['settings' => $settings])->save();
        $this->recorder->record('site.design', $site, $before, $design, $site);
    }
}
