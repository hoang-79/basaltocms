<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Sites\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eine Meldung zu einem Inhalt (CR-006 FO-K9, TB 39): Grund aus einer festen Liste, freiwillig eine Notiz; offen, bis
 * die Moderation entscheidet — Inhalt ausgeblendet (`hidden`) oder Meldung zurückgewiesen (`kept`). Jeder Inhaltstyp
 * meldet sich bei {@see Reports} an; die Bewertungen des Marktplatzes (TB 26) sind einer davon.
 *
 * @property int $id
 * @property int $site_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $customer_id
 * @property string $reporter
 * @property string $reason
 * @property string|null $note
 * @property string $status
 * @property int|null $resolved_by
 * @property int|null $resolved_by_customer
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CommunityReport extends Model
{
    use BelongsToSite;

    public const OPEN = 'open';

    public const HIDDEN = 'hidden';

    public const KEPT = 'kept';

    /** Gründe einer Meldung — Schlüssel → Text (ein Inhaltstyp darf einzelne Texte anders fassen) */
    public const REASONS = [
        'werbung' => 'Werbung oder Spam',
        'beleidigung' => 'Beleidigend oder herabsetzend',
        'personendaten' => 'Enthält persönliche Daten',
        'falsch' => 'Gehört nicht hierher',
        'anderes' => 'Anderes',
    ];

    protected $table = 'community_reports';

    protected $fillable = ['site_id', 'subject_type', 'subject_id', 'customer_id', 'reporter', 'reason', 'note', 'status', 'resolved_by', 'resolved_by_customer', 'resolved_at'];

    protected function casts(): array
    {
        return ['subject_id' => 'integer', 'resolved_at' => 'datetime'];
    }
}
