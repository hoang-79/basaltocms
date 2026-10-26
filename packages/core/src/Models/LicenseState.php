<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Zeile je Installation: seit wann sie gegen ihre Lizenz verstösst (D-12). Wird bei jeder Prüfung nachgeführt,
 * das Frontend liest nur dieses Datum — ohne Benutzer oder Sites zu zählen.
 *
 * @property ?CarbonImmutable $unregistered_since
 * @property ?CarbonImmutable $checked_at
 */
final class LicenseState extends Model
{
    protected $table = 'license_state';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unregistered_since' => 'immutable_datetime', 'checked_at' => 'immutable_datetime'];
    }

    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([]);
    }

    /** Verstoss beginnt beim ersten Befund und endet, sobald die Installation wieder regelkonform ist. */
    public function record(bool $compliant): void
    {
        $this->forceFill([
            'checked_at' => CarbonImmutable::now(),
            'unregistered_since' => $compliant ? null : ($this->unregistered_since ?? CarbonImmutable::now()),
        ])->save();
    }

    public static function bannerActive(int $days): bool
    {
        $since = self::query()->first()?->unregistered_since;

        return $since !== null && $since->addDays($days)->isPast();
    }
}
