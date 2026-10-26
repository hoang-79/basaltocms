<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Umzug eines Kontos zwischen den Instanzen «start» und «pro» (TB 12 Scheibe 4, ADR-008 Konsequenz 4). Dieselbe UUID
 * steht auf beiden Seiten: auf der Quelle als `out` (Export, Übergabe, «umgezogen», Bereinigung), auf dem Ziel als `in`
 * (Archiv holen, Import, Rückmeldung, Bestätigung des Besitzers).
 *
 * Stand `out`: exporting → sent → done → cleaned, bei Fehlern failed. Stand `in`: queued → imported → cleaned, sonst failed.
 *
 * @property int $id
 * @property string $uuid
 * @property string $direction
 * @property ?int $account_id
 * @property string $source
 * @property string $target
 * @property string $status
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $sha256
 * @property ?int $size
 * @property array<string, mixed>|null $result
 * @property ?string $error
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $confirmed_at
 * @property CarbonImmutable $created_at
 */
final class InstanceMove extends Model
{
    public const OUT = 'out';

    public const IN = 'in';

    public const EXPORTING = 'exporting';

    public const SENT = 'sent';

    public const QUEUED = 'queued';

    public const IMPORTED = 'imported';

    public const DONE = 'done';

    public const CLEANED = 'cleaned';

    public const FAILED = 'failed';

    protected $fillable = ['uuid', 'direction', 'account_id', 'source', 'target', 'status', 'disk', 'path', 'sha256', 'size', 'result', 'error', 'completed_at', 'confirmed_at'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'result' => 'array', 'completed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::EXPORTING, self::SENT, self::QUEUED], true);
    }
}
