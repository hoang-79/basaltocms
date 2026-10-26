<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Zeile im Moderationsprotokoll (CR-006 FO-15): Aktion, Inhalt (Typ, Kennung, Bezeichnung zum Zeitpunkt), wer
 * moderiert hat (Benutzer oder Kundenkonto), wen es betrifft, Grund und Einzelheiten.
 *
 * @property int $id
 * @property int $site_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property int|null $target_customer_id
 * @property string|null $reason
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
final class ModerationEntry extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    protected $table = 'community_moderation_log';

    protected $fillable = ['site_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'user_id', 'customer_id', 'target_customer_id', 'reason', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'subject_id' => 'integer'];
    }
}
