<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai;

use Basalto\Core\Models\Site;
use Basalto\Modules\Ai\Models\AiSetting;
use Basalto\Modules\Ai\Models\AiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Führt eine KI-Funktion für eine Site aus (M-24) — immer auf eine ausdrückliche Anfrage aus der Oberfläche hin, nie
 * von selbst. Prüft Schlüssel, Schalter der Funktion, Tageslimit und Kostenbremse; baut den Anbieter mit dem Schlüssel
 * der Site (Laravel AI SDK, «on demand»), fragt und schreibt jede Nutzung ins Protokoll — ohne Inhalte, ohne Schlüssel.
 * Fehler des Anbieters werden zu einer freundlichen Meldung; das Ergebnis ist nur ein Vorschlag, gespeichert wird hier nichts.
 */
final class Assistant
{
    /** Längste Eingabe in Zeichen — mehr geht nicht an den Anbieter. */
    public const MAX_INPUT = 12000;

    /**
     * @param  list<mixed>  $attachments
     * @return array{usage: AiUsage, data: array<string, mixed>}
     *
     * @throws AssistantException
     */
    public function run(Site $site, Authenticatable $user, Feature $feature, Agent $agent, string $prompt, array $attachments = [], ?Model $subject = null, ?string $locale = null): array
    {
        $settings = AiSetting::forSite($site);
        $this->ensureAvailable($settings, $feature, $site);
        if (mb_strlen($prompt) > self::MAX_INPUT) {
            throw new AssistantException(__('Der Text ist zu lang für einen Vorschlag (höchstens :max Zeichen).', ['max' => number_format(self::MAX_INPUT, 0, '.', "'")]));
        }
        $config = ['name' => 'basalto-ai-site-'.$site->id, 'driver' => $settings->provider, 'key' => (string) $settings->api_key];
        // Ein Gateway oder Proxy für die ganze Installation (z. B. OPENAI_URL, ANTHROPIC_URL aus config/ai.php) gilt auch hier
        $url = config('ai.providers.'.$settings->provider.'.url');
        if (is_string($url) && $url !== '') {
            $config['url'] = $url;
        }
        if ($settings->model !== null && $settings->model !== '') {
            $config['models'] = ['text' => ['default' => $settings->model]];
        }
        $usage = new AiUsage([
            'site_id' => $site->id, 'user_id' => (int) $user->getAuthIdentifier(), 'feature' => $feature->value,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject === null ? null : (int) $subject->getKey(), 'locale' => $locale,
            'provider' => $settings->provider, 'model' => $settings->model,
        ]);
        try {
            $response = $agent->prompt($prompt, $attachments, provider: [Ai::build($config)]);
        } catch (Throwable $e) {
            $usage->fill(['status' => AiUsage::FAILED, 'error' => $this->category($e)])->save();
            // Ins Log nur Art und Kategorie — die Meldung des Anbieters kann Teile der Anfrage enthalten
            Log::warning('KI-Vorschlag gescheitert', ['site' => $site->id, 'feature' => $feature->value, 'provider' => $settings->provider, 'error' => $usage->error, 'class' => $e::class]);

            throw new AssistantException($this->friendly($usage->error ?? 'other', $settings->provider), 502);
        } finally {
            Ai::flushState();
        }
        $usage->fill([
            'status' => AiUsage::OK,
            'model' => $response->meta->model ?? $settings->model,
            'input_tokens' => $response->usage->inputTokens ?: null,
            'output_tokens' => $response->usage->outputTokens ?: null,
        ])->save();

        return ['usage' => $usage, 'data' => $this->data($response)];
    }

    /**
     * Darf die Funktion jetzt laufen? Ohne Schlüssel, ausgeschaltet, über dem Tageslimit oder der Kostenbremse nicht.
     *
     * @throws AssistantException
     */
    public function ensureAvailable(AiSetting $settings, Feature $feature, Site $site): void
    {
        if (! $settings->hasKey()) {
            throw new AssistantException(__('Für diese Site ist kein KI-Schlüssel hinterlegt — unter Verwaltung › KI-Vorschläge eintragen.'));
        }
        if (! $settings->featureOn($feature)) {
            throw new AssistantException(__('Die Funktion «:feature» ist für diese Site ausgeschaltet.', ['feature' => $feature->label()]));
        }
        $today = AiUsage::query()->where('site_id', $site->id)->where('created_at', '>=', CarbonImmutable::now($site->timezone ?: 'Europe/Zurich')->startOfDay()->utc())->count();
        if ($today >= $settings->daily_limit) {
            throw new AssistantException(trans_choice('Das Tageslimit von :count Vorschlag ist erreicht — morgen geht es weiter, oder die Verwaltung erhöht es.|Das Tageslimit von :count Vorschlägen ist erreicht — morgen geht es weiter, oder die Verwaltung erhöht es.', $settings->daily_limit, ['count' => $settings->daily_limit]), 429);
        }
        if ($settings->monthly_token_limit !== null && $this->tokensThisMonth($site) >= $settings->monthly_token_limit) {
            throw new AssistantException(__('Die Kostenbremse für diesen Monat ist erreicht (:tokens Tokens) — die Verwaltung kann sie erhöhen.', ['tokens' => number_format($settings->monthly_token_limit, 0, '.', "'")]), 429);
        }
    }

    public function tokensThisMonth(Site $site): int
    {
        $start = CarbonImmutable::now($site->timezone ?: 'Europe/Zurich')->startOfMonth()->utc();

        return (int) AiUsage::query()->where('site_id', $site->id)->where('created_at', '>=', $start)
            ->selectRaw('coalesce(sum(input_tokens), 0) + coalesce(sum(output_tokens), 0) as total')->value('total');
    }

    /** @return array<string, mixed> */
    private function data(AgentResponse $response): array
    {
        if ($response instanceof StructuredAgentResponse) {
            return $response->structured;
        }

        return ['text' => $response->text];
    }

    /** Die Art des Fehlers fürs Protokoll — nie die Meldung selbst. */
    private function category(Throwable $e): string
    {
        $status = $e instanceof RequestException ? $e->response->status() : null;

        return match (true) {
            $e instanceof RateLimitedException, $status === 429 => 'rate_limited',
            $e instanceof InsufficientCreditsException, $status === 402 => 'no_credits',
            $e instanceof ProviderOverloadedException, $e instanceof ProviderConnectionException, $status !== null && $status >= 500 => 'unavailable',
            $status === 401, $status === 403 => 'auth',
            $status === 400, $status === 404, $status === 422 => 'request',
            default => 'other',
        };
    }

    private function friendly(string $category, string $provider): string
    {
        $name = Providers::label($provider);

        return match ($category) {
            'rate_limited' => __(':provider bremst gerade (zu viele Anfragen) — bitte in einer Minute noch einmal.', ['provider' => $name]),
            'no_credits' => __('Beim Konto von :provider ist das Guthaben aufgebraucht — bitte dort aufladen.', ['provider' => $name]),
            'unavailable' => __(':provider ist gerade nicht erreichbar — bitte später noch einmal.', ['provider' => $name]),
            'auth' => __(':provider hat den Schlüssel abgelehnt — bitte unter Verwaltung › KI-Vorschläge prüfen.', ['provider' => $name]),
            'request' => __(':provider hat die Anfrage nicht angenommen — stimmt das Modell?', ['provider' => $name]),
            default => __('Der Vorschlag ist nicht gekommen — :provider hat nicht wie erwartet geantwortet. Bitte noch einmal.', ['provider' => $name]),
        };
    }
}
