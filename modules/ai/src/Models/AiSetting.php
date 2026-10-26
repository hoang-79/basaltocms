<?php

declare(strict_types=1);

namespace Basalto\Modules\Ai\Models;

use Basalto\Core\Models\Site;
use Basalto\Modules\Ai\Feature;
use Basalto\Modules\Ai\Providers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Die KI-Einstellung einer Site (M-24). Der Schlüssel des Kunden liegt verschlüsselt (Cast `encrypted`, App-Schlüssel)
 * und ist verborgen — er verlässt den Server nur in der Anfrage an den Anbieter, nie in Props, Protokoll oder Export.
 *
 * @property int $id
 * @property int $site_id
 * @property string $provider
 * @property string|null $model
 * @property string|null $api_key
 * @property int $daily_limit
 * @property int|null $monthly_token_limit
 * @property array<string, bool>|null $features
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiSetting extends Model
{
    public const DEFAULT_DAILY_LIMIT = 100;

    protected $table = 'ai_settings';

    protected $fillable = ['site_id', 'provider', 'model', 'api_key', 'daily_limit', 'monthly_token_limit', 'features', 'updated_by'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'features' => 'array', 'daily_limit' => 'integer', 'monthly_token_limit' => 'integer'];
    }

    /** Die Einstellung einer Site — ohne Zeile ein ungespeicherter Startwert (Anbieter OpenAI, kein Schlüssel). */
    public static function forSite(Site $site): self
    {
        return self::query()->where('site_id', $site->id)->first()
            ?? new self(['site_id' => $site->id, 'provider' => 'openai', 'daily_limit' => self::DEFAULT_DAILY_LIMIT, 'features' => self::defaultFeatures()]);
    }

    /** @return array<string, bool> Ab Werk sind alle Funktionen an — wirksam erst mit Schlüssel. */
    public static function defaultFeatures(): array
    {
        $out = [];
        foreach (Feature::cases() as $feature) {
            $out[$feature->value] = true;
        }

        return $out;
    }

    public function hasKey(): bool
    {
        return $this->exists && is_string($this->api_key) && $this->api_key !== '';
    }

    /** «…1234» — die letzten vier Zeichen, nur für die Einstellungsseite. */
    public function keyHint(): ?string
    {
        if (! $this->hasKey()) {
            return null;
        }

        return '…'.mb_substr((string) $this->api_key, -4);
    }

    /** Ist eine Funktion eingeschaltet (und kann der Anbieter sie)? Alt-Texte brauchen einen Anbieter, der Bilder liest. */
    public function featureOn(Feature $feature): bool
    {
        $features = ($this->features ?? []) + self::defaultFeatures();
        if ($feature === Feature::AltText && ! Providers::vision($this->provider)) {
            return false;
        }

        return (bool) ($features[$feature->value] ?? false);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
