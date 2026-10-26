<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Eintrag im Protokoll (K-15): wer, wann, was, alt/neu — je Site oder installationsweit; IP nur als Prüfsumme.
 *
 * @property int $id
 * @property ?int $site_id
 * @property ?int $user_id
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property ?string $ip_hash
 * @property CarbonImmutable $created_at
 */
final class Activity extends Model
{
    protected $table = 'activity_log';

    public $timestamps = false;

    protected $fillable = ['site_id', 'user_id', 'subject_type', 'subject_id', 'action', 'before', 'after', 'ip_hash', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
