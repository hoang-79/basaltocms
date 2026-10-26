<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein Export aus dem Konto-Bereich (TB 12 Scheibe 4, M-21): eine Site oder das ganze Konto, als Job gebaut, im privaten
 * Speicher abgelegt und {@see self::DAYS} Tage über einen signierten Link abrufbar; danach löscht der Löschlauf Datei und Zeile.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property ?int $site_id
 * @property ?int $user_id
 * @property string $status
 * @property int $progress
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $filename
 * @property ?int $size
 * @property ?string $error
 * @property ?CarbonImmutable $ready_at
 * @property ?CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
final class AccountExport extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = ['uuid', 'account_id', 'site_id', 'user_id', 'status', 'progress', 'disk', 'path', 'filename', 'size', 'error', 'ready_at', 'expires_at'];

    protected function casts(): array
    {
        return ['progress' => 'integer', 'size' => 'integer', 'ready_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $export): void {
            $export->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::READY && $this->expires_at !== null && $this->expires_at->isFuture() && $this->path !== null;
    }
}
