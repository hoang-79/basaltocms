<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Ai\Assistant;
use Basalto\Modules\Ai\Feature;
use Basalto\Modules\Ai\Models\AiSetting;
use Basalto\Modules\Ai\Models\AiUsage;
use Basalto\Modules\Ai\Providers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verwaltung › KI-Vorschläge (M-24): Anbieter, Modell und Schlüssel des Kunden je Site (der Schlüssel geht nie an den
 * Browser — nur «gesetzt · …1234»), Tageslimit, Kostenbremse in Tokens je Monat, Schalter je Funktion; dazu die
 * Nutzung je Monat und die letzten Aufrufe aus dem Protokoll. Nur für Betreiber und Administration der Site.
 */
final class SettingsController
{
    public function __construct(private readonly CurrentSite $current, private readonly Dates $dates) {}

    public function index(Request $request, Assistant $assistant): Response
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $settings = AiSetting::forSite($site);
        $today = AiUsage::query()->where('site_id', $site->id)->where('created_at', '>=', CarbonImmutable::now($site->timezone ?: 'Europe/Zurich')->startOfDay()->utc())->count();

        return Inertia::render('ai::Index', [
            'settings' => [
                'provider' => $settings->provider,
                'model' => (string) ($settings->model ?? ''),
                'has_key' => $settings->hasKey(),
                'key_hint' => $settings->keyHint(),
                'daily_limit' => $settings->daily_limit,
                'monthly_token_limit' => $settings->monthly_token_limit,
                'features' => array_map(static fn (Feature $f): bool => (bool) (($settings->features ?? []) + AiSetting::defaultFeatures())[$f->value], array_combine(array_map(static fn (Feature $f): string => $f->value, Feature::cases()), Feature::cases())),
            ],
            'features' => array_map(static fn (Feature $f): array => ['value' => $f->value, 'label' => $f->label(), 'help' => match ($f) {
                Feature::Improve => __('Im Block-Editor an Text- und Fliesstextfeldern'),
                Feature::AltText => __('In der Mediathek am Bild — braucht einen Anbieter, der Bilder liest'),
                Feature::Translate => __('Beim Übersetzen nebeneinander, je Feld aus der Quellfassung'),
                Feature::Seo => __('In den Seiteneinstellungen, Reiter Metadaten'),
            }], Feature::cases()),
            'providers' => array_map(static fn (string $key): array => ['value' => $key, 'label' => Providers::label($key), 'vision' => Providers::vision($key),
                'default_model' => Providers::defaultModel($key), 'privacy_url' => Providers::ALL[$key]['privacy']], Providers::keys()),
            'today' => ['count' => $today, 'limit' => $settings->daily_limit, 'tokens_month' => $assistant->tokensThisMonth($site)],
            'months' => $this->months($site->id, $site->timezone ?: 'Europe/Zurich'),
            'recent' => $this->recent($site->id),
            'urls' => ['update' => route('basalto.ai.update'), 'key' => route('basalto.ai.key.destroy')],
        ]);
    }

    public function update(Request $request, Recorder $recorder): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $validated = $request->validate([
            'provider' => ['required', Rule::in(Providers::keys())],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/@-]+$/'],
            'api_key' => ['nullable', 'string', 'min:8', 'max:500', 'regex:/^\S+$/'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'monthly_token_limit' => ['nullable', 'integer', 'min:1000', 'max:10000000000'],
            'features' => ['array'],
            'features.*' => ['boolean'],
        ], [], ['api_key' => __('Schlüssel'), 'daily_limit' => __('Tageslimit'), 'monthly_token_limit' => __('Kostenbremse')]);
        $settings = AiSetting::forSite($site);
        $before = $this->audit($settings);
        $features = [];
        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = (bool) ($validated['features'][$feature->value] ?? false);
        }
        $keyChanged = ($validated['api_key'] ?? null) !== null && $validated['api_key'] !== '';
        $settings->fill([
            'provider' => $validated['provider'],
            'model' => ($validated['model'] ?? '') === '' ? null : $validated['model'],
            'daily_limit' => (int) $validated['daily_limit'],
            'monthly_token_limit' => $validated['monthly_token_limit'] ?? null,
            'features' => $features,
            'updated_by' => (int) $request->user()?->getAuthIdentifier(),
        ]);
        if ($keyChanged) {
            $settings->api_key = trim((string) $validated['api_key']);
        }
        $settings->save();
        // Ins Protokoll des Kerns: was sich geändert hat — vom Schlüssel nur, dass er neu ist
        $recorder->record('ai.settings.changed', $settings, $before, $this->audit($settings) + ['key_changed' => $keyChanged], $site);

        return back()->with('status', $keyChanged ? __('KI-Einstellungen gespeichert — neuer Schlüssel hinterlegt.') : __('KI-Einstellungen gespeichert.'));
    }

    /** Den Schlüssel entfernen — danach sind alle KI-Knöpfe der Site aus. */
    public function destroyKey(Recorder $recorder): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('update', $site);
        $settings = AiSetting::forSite($site);
        if ($settings->hasKey()) {
            $settings->forceFill(['api_key' => null])->save();
            $recorder->record('ai.key.removed', $settings, null, ['provider' => $settings->provider], $site);
        }

        return back()->with('status', __('KI-Schlüssel entfernt — die KI-Knöpfe sind jetzt aus.'));
    }

    /** @return array<string, mixed> Einstellung fürs Protokoll — ohne Schlüssel */
    private function audit(AiSetting $settings): array
    {
        return ['provider' => $settings->provider, 'model' => $settings->model, 'daily_limit' => $settings->daily_limit,
            'monthly_token_limit' => $settings->monthly_token_limit, 'features' => $settings->features, 'has_key' => $settings->hasKey()];
    }

    /**
     * Nutzung je Monat, die letzten zwölf: Vorschläge, davon übernommen und gescheitert, Tokens, je Funktion.
     *
     * @return list<array{month: string, label: string, count: int, applied: int, failed: int, tokens: int, features: array<string, int>}>
     */
    private function months(int $siteId, string $timezone): array
    {
        $start = CarbonImmutable::now($timezone)->startOfMonth()->subMonths(11);
        $months = [];
        foreach (AiUsage::query()->where('site_id', $siteId)->where('created_at', '>=', $start->utc())->orderBy('id')
            ->select(['id', 'feature', 'status', 'applied_at', 'input_tokens', 'output_tokens', 'created_at'])->lazyById(1000) as $usage) {
            $created = CarbonImmutable::instance($usage->created_at ?? now())->setTimezone($timezone);
            $key = $created->format('Y-m');
            $months[$key] ??= ['month' => $key, 'label' => $created->translatedFormat('F Y'), 'count' => 0, 'applied' => 0, 'failed' => 0, 'tokens' => 0,
                'features' => array_fill_keys(array_map(static fn (Feature $f): string => $f->value, Feature::cases()), 0)];
            $months[$key]['count']++;
            $months[$key]['applied'] += $usage->applied_at !== null ? 1 : 0;
            $months[$key]['failed'] += $usage->status === AiUsage::FAILED ? 1 : 0;
            $months[$key]['tokens'] += (int) $usage->input_tokens + (int) $usage->output_tokens;
            if (isset($months[$key]['features'][$usage->feature])) {
                $months[$key]['features'][$usage->feature]++;
            }
        }
        krsort($months);

        return array_values($months);
    }

    /** @return list<array<string, mixed>> Die letzten 50 Aufrufe — ohne Inhalte */
    private function recent(int $siteId): array
    {
        $rows = AiUsage::query()->where('site_id', $siteId)->latest('id')->limit(50)->get();
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $users = $userModel::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $entries = Entry::query()->with('versions')->whereIn('id', $rows->where('subject_type', (new Entry)->getMorphClass())->pluck('subject_id')->unique())->get()->keyBy('id');
        $media = Media::query()->withTrashed()->whereIn('id', $rows->where('subject_type', (new Media)->getMorphClass())->pluck('subject_id')->unique())->pluck('name', 'id');

        return array_values($rows->map(function (AiUsage $usage) use ($users, $entries, $media): array {
            $subject = null;
            if ($usage->subject_type === (new Entry)->getMorphClass() && ($entry = $entries->get($usage->subject_id)) !== null) {
                $version = $entry->versions->sortByDesc('number')->first();
                $subject = ['label' => (string) ($version->title ?? __('(ohne Titel)')), 'url' => route('basalto.entries.edit', [$entry, $usage->locale ?? $entry->site->default_locale])];
            } elseif ($usage->subject_type === (new Media)->getMorphClass() && $media->has($usage->subject_id)) {
                $subject = ['label' => (string) $media->get($usage->subject_id), 'url' => route('basalto.media.index', ['media' => $usage->subject_id])];
            }
            $feature = Feature::tryFrom($usage->feature);

            return [
                'id' => $usage->id,
                'at' => $this->dates->dateTime($usage->created_at),
                'user' => $usage->user_id === null ? '—' : (string) ($users->get($usage->user_id) ?? '—'),
                'feature' => $usage->feature,
                'feature_label' => $feature?->label() ?? $usage->feature,
                'subject' => $subject,
                'locale' => $usage->locale,
                'provider' => Providers::label($usage->provider),
                'model' => $usage->model,
                'tokens' => $usage->input_tokens === null && $usage->output_tokens === null ? null : (int) $usage->input_tokens + (int) $usage->output_tokens,
                'status' => $usage->status,
                'error' => $usage->error === null ? null : $this->errorLabel($usage->error),
                'applied' => $usage->applied_at !== null,
            ];
        })->all());
    }

    private function errorLabel(string $category): string
    {
        return match ($category) {
            'rate_limited' => __('Anbieter bremst'),
            'no_credits' => __('Guthaben aufgebraucht'),
            'unavailable' => __('Anbieter nicht erreichbar'),
            'auth' => __('Schlüssel abgelehnt'),
            'request' => __('Anfrage abgelehnt'),
            default => __('Unerwartete Antwort'),
        };
    }
}
