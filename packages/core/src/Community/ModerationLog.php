<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Models\Site;

/**
 * Das Moderationsprotokoll (CR-006 FO-15): jede Moderationsaktion, ob aus dem Frontend oder dem Extranet, mit
 * derselben Zeile. Module melden die Bezeichnungen ihrer Aktionen an (Singleton, beim Booten).
 */
final class ModerationLog
{
    /** @var array<string, string> Aktion → Bezeichnung */
    private array $labels = [
        'report.hidden' => 'Meldung: ausgeblendet',
        'report.kept' => 'Meldung zurückgewiesen',
        'member.warned' => 'Verwarnt',
        'member.warning_revoked' => 'Verwarnung zurückgenommen',
        'member.muted' => 'Schreibsperre',
        'member.unmuted' => 'Schreibsperre aufgehoben',
        'member.banned' => 'Gesperrt',
        'member.unbanned' => 'Sperre aufgehoben',
        'member.spammer' => 'Als Spammer entfernt',
    ];

    /** @param  array<string, string>  $labels */
    public function labels(array $labels): self
    {
        $this->labels = [...$this->labels, ...$labels];

        return $this;
    }

    public function label(string $action, ?string $lang = null): string
    {
        return (string) __($this->labels[$action] ?? $action, [], $lang);
    }

    /** @param  array<string, mixed>  $data */
    public function record(Site|int $site, string $action, Moderator $by, ?string $subjectType = null, ?int $subjectId = null, ?string $subjectLabel = null,
        ?int $targetCustomerId = null, ?string $reason = null, array $data = []): ModerationEntry
    {
        return ModerationEntry::query()->withoutGlobalScopes()->create(['site_id' => $site instanceof Site ? $site->id : $site, 'action' => $action,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'subject_label' => $subjectLabel === null ? null : mb_substr($subjectLabel, 0, 200),
            'user_id' => $by->userId(), 'customer_id' => $by->customerId(), 'target_customer_id' => $targetCustomerId,
            'reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500), 'data' => $data === [] ? null : $data]);
    }
}
