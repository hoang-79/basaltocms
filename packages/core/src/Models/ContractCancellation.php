<?php

declare(strict_types=1);

namespace Basalto\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Eine Kündigung über «Verträge hier kündigen» (CR-011 TB 59, § 312k BGB). Status:
 * `applied` (Vertrag gekündigt, Ende in `ends_at`), `scheduled` (Wunschdatum nach dem nächsten Ende — der tägliche Lauf
 * kündigt, sobald es erreicht ist), `review` (das Team ordnet zu), `done` (vom Team erledigt), `rejected` (kein
 * Vertrag gefunden) und `revoked` (zurückgenommen).
 *
 * @property int $id
 * @property ?int $site_id
 * @property string $number
 * @property string $kind
 * @property ?string $reason
 * @property string $name
 * @property string $email
 * @property string $contract
 * @property ?string $reference
 * @property ?CarbonImmutable $wish_date
 * @property string $locale
 * @property string $status
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?CarbonImmutable $ends_at
 * @property CarbonImmutable $received_at
 * @property ?CarbonImmutable $handled_at
 * @property ?int $handled_by
 * @property ?string $note
 * @property Carbon|null $created_at
 */
final class ContractCancellation extends Model
{
    public const ORDINARY = 'ordinary';

    public const EXTRAORDINARY = 'extraordinary';

    public const APPLIED = 'applied';

    public const SCHEDULED = 'scheduled';

    public const REVIEW = 'review';

    public const DONE = 'done';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    /** Vertrag ohne Quelle — das Team ordnet zu. */
    public const OTHER = 'other';

    protected $fillable = ['site_id', 'number', 'kind', 'reason', 'name', 'email', 'contract', 'reference', 'wish_date', 'locale', 'status',
        'subject_type', 'subject_id', 'ends_at', 'received_at', 'handled_at', 'handled_by', 'note'];

    protected function casts(): array
    {
        return ['wish_date' => 'immutable_date', 'ends_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'handled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Der gekündigte Vertrag (Konto der SaaS, Agentur-Lizenz …) — ohne Scope auf die aktuelle Site.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    /** Läuft noch etwas, das sich zurücknehmen lässt? */
    public function revocable(): bool
    {
        return in_array($this->status, [self::APPLIED, self::SCHEDULED], true) && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** Nächste Nummer im Jahr des Eingangs: K-2026-0001, K-2026-0002 … */
    public static function nextNumber(CarbonImmutable $at): string
    {
        $prefix = sprintf('K-%d-', $at->year);
        $last = DB::table('contract_cancellations')->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $next = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
