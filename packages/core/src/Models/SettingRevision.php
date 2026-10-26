<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Der Verlauf einer Einstellung: wer hat wann welchen Wert durch welchen ersetzt (K-07).
 *
 * @property int $id
 * @property int $setting_id
 * @property mixed $old
 * @property mixed $new
 * @property ?int $user_id
 * @property CarbonImmutable $created_at
 */
final class SettingRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['setting_id', 'old', 'new', 'user_id', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['old' => 'json', 'new' => 'json', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Setting, $this> */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
