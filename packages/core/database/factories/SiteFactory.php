<?php

declare(strict_types=1);

namespace Basalto\Core\Database\Factories;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Site> */
final class SiteFactory extends Factory
{
    protected $model = Site::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'account_id' => Account::factory(),
            'handle' => str($name)->slug()->limit(30, '')->toString(),
            'name' => $name,
            'default_locale' => 'de',
            'timezone' => 'Europe/Zurich',
            'live_at' => now()->subMinute(),
        ];
    }

    /** Eine Site, die noch nicht mit `basalto:publish` live geschaltet ist. */
    public function notLive(): self
    {
        return $this->state(['live_at' => null]);
    }

    /**
     * Sprachen anlegen: erste ist Standard und Pflicht; Fallback jeweils auf die Standardsprache.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $required
     */
    public function withLocales(array $locales = ['de', 'en'], array $required = []): self
    {
        return $this->afterCreating(static function (Site $site) use ($locales, $required): void {
            foreach ($locales as $i => $locale) {
                $site->locales()->create([
                    'locale' => $locale,
                    'is_default' => $locale === $site->default_locale,
                    'is_required' => $locale === $site->default_locale || in_array($locale, $required, true),
                    'fallback_locale' => $locale === $site->default_locale ? null : $site->default_locale,
                    'sort_order' => $i,
                ]);
            }
        });
    }
}
