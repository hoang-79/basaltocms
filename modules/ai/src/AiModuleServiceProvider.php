<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai;

use Basalto\Core\Access\Areas;
use Basalto\Core\Access\Level;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Models\Site;
use Basalto\Modules\Ai\Http\Controllers\SettingsController;
use Basalto\Modules\Ai\Http\Controllers\SuggestionController;
use Basalto\Modules\Ai\Http\Middleware\EnsureAiEnabled;
use Basalto\Modules\Ai\Models\AiSetting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * KI-Vorschläge als Modul (M-24), je Site schaltbar und ab Werk aus. Mit dem Schlüssel des Kunden (Laravel AI SDK)
 * schlägt es vor — Text verbessern im Block-Editor, Alt-Texte in der Mediathek, Übersetzung beim Übersetzen
 * nebeneinander, Seitentitel und Beschreibung im Reiter Metadaten. Nur auf Klick, immer als Vorschlag, übernommen wird
 * in den Entwurf; jede Nutzung steht im Protokoll (ohne Inhalte, ohne Schlüssel). Die Knöpfe hängen sich über
 * `Backend::share()` und `ModuleSlot` in die Seiten des Kerns; Verwaltung › KI-Vorschläge hält Schlüssel und Nutzung.
 */
final class AiModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // CR-005 (R-01): Bereich des Moduls und seine Routen
        app(Areas::class)->register('ai', 'KI-Vorschläge', Areas::CONTENT, 'ai')->map('basalto.ai.', 'ai')->route('basalto.ai.index', 'ai', Level::All)->route('basalto.ai.update', 'ai', Level::All)->route('basalto.ai.key.destroy', 'ai', Level::All);
        $root = dirname(__DIR__);
        $this->loadTranslationsFrom($root.'/lang', 'ai');
        $this->loadJsonTranslationsFrom($root.'/lang');
        $this->loadMigrationsFrom($root.'/database/migrations');

        $backend = $this->app->make(Backend::class);
        $backend->module('ai', 'ai::backend.module', switchable: true, composerJson: $root.'/composer.json', enabledByDefault: false);
        $backend->pages('ai', $root.'/resources/js/pages');
        $backend->menu('ai::backend.title', 'basalto.ai.index', 47, Backend::MODE_ADMIN, module: 'ai', icon: 'module');
        $backend->visibleWhen('basalto.ai.index', static fn (?Authenticatable $user, ?Site $site): bool => $user !== null && $site !== null && Gate::forUser($user)->allows('area', [$site, 'ai', Level::All]));
        $backend->share('ai', static fn (?Site $site): ?array => self::shared($site, $backend));
        $backend->routes(static function (): void {
            Route::middleware(EnsureAiEnabled::class)->group(static function (): void {
                Route::get('ki', [SettingsController::class, 'index'])->name('ai.index');
                Route::put('ki', [SettingsController::class, 'update'])->name('ai.update');
                Route::delete('ki/schluessel', [SettingsController::class, 'destroyKey'])->name('ai.key.destroy');
                Route::post('ki/vorschlag/text', [SuggestionController::class, 'improve'])->name('ai.improve');
                Route::post('ki/vorschlag/uebersetzung', [SuggestionController::class, 'translate'])->name('ai.translate');
                Route::post('ki/vorschlag/seo', [SuggestionController::class, 'seo'])->name('ai.seo');
                Route::post('ki/vorschlag/alt-text', [SuggestionController::class, 'altText'])->name('ai.alt-text');
                Route::post('ki/nutzung/{usage}/uebernommen', [SuggestionController::class, 'applied'])->whereNumber('usage')->name('ai.applied');
            });
        });
    }

    /**
     * Was die Knöpfe in den Seiten des Kerns brauchen — ohne Schlüssel: bereit oder nicht (mit Grund), welche
     * Funktionen an sind, Anbieter für den Datenschutz-Hinweis, Adressen. `null`, wenn das Modul auf der Site aus ist.
     *
     * @return array<string, mixed>|null
     */
    private static function shared(?Site $site, Backend $backend): ?array
    {
        if ($site === null || ! $backend->moduleEnabled('ai', $site)) {
            return null;
        }
        $settings = AiSetting::forSite($site);
        $user = auth()->user();
        $canConfigure = $user !== null && Gate::forUser($user)->allows('area', [$site, 'ai', Level::All]);
        $features = [];
        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = $settings->hasKey() && $settings->featureOn($feature);
        }

        return [
            'ready' => $settings->hasKey(),
            'notice' => $settings->hasKey() ? null : ($canConfigure
                ? __('KI-Vorschläge sind aus: Für diese Site ist kein Schlüssel hinterlegt — unter Verwaltung › KI-Vorschläge eintragen.')
                : __('KI-Vorschläge sind aus: Für diese Site ist kein Schlüssel hinterlegt — bitte die Verwaltung der Site fragen.')),
            'features' => $features,
            'provider' => Providers::label($settings->provider),
            'privacy' => __('Der Inhalt geht zur Bearbeitung an :provider (mit dem Schlüssel dieser Site). Der Vorschlag ändert nichts, bis Sie ihn übernehmen.', ['provider' => Providers::label($settings->provider)]),
            'urls' => [
                'improve' => route('basalto.ai.improve'),
                'translate' => route('basalto.ai.translate'),
                'seo' => route('basalto.ai.seo'),
                'alt_text' => route('basalto.ai.alt-text'),
                'settings' => $canConfigure ? route('basalto.ai.index') : null,
            ],
        ];
    }
}
