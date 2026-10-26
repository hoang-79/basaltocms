<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Einstellung je Site oder installationsweit (site_id null), Wert als JSON; jede Änderung schreibt eine Revision (K-07).
 *
 * @property int $id
 * @property ?int $site_id
 * @property string $key
 * @property mixed $value
 * @property ?int $updated_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class Setting extends Model
{
    protected $fillable = ['site_id', 'key', 'value', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @return HasMany<SettingRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SettingRevision::class)->orderByDesc('id');
    }
}
