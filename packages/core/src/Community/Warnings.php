<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;

/**
 * Verwarnungen mit Punkten und Folgen (CR-006 FO-17), Vorbild WoltLab: Jede Verwarnung hat Punkte und läuft ab; die
 * gültigen Punkte eines Kontos lösen ab den Schwellen der Einstellungen eine Schreibsperre oder eine Sperre aus — für
 * die eingestellte Zahl Tage. Die verwarnte Person bekommt eine Nachricht in die Glocke.
 */
final class Warnings
{
    public const NOTIFICATION = 'community.warning';

    public function __construct(private readonly ModerationLog $log, private readonly Sanctions $sanctions, private readonly ModerationSettings $settings) {}

    /**
     * @param  int|null  $days  Gültigkeit; ohne Angabe die Vorgabe der Site (0 = läuft nicht ab)
     * @return array{warning: CommunityWarning, consequence: string|null} Folge: «muted», «banned» oder null
     */
    public function warn(Site $site, Customer $customer, Moderator $by, string $reason, int $points, ?int $days = null, ?string $note = null,
        ?string $subjectType = null, ?int $subjectId = null): array
    {
        $days ??= $this->settings->int($site, 'warning_days');
        $warning = CommunityWarning::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'customer_id' => $customer->id, 'reason' => mb_substr(trim($reason), 0, 200),
            'points' => max(0, min(100, $points)), 'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'issued_by' => $by->userId(), 'issued_by_customer' => $by->customerId(),
            'expires_at' => $days > 0 ? now()->addDays($days) : null]);
        $this->log->record($site, 'member.warned', $by, 'customer', $customer->id, $this->sanctions->profile($customer)->name ?? $customer->email, $customer->id, $reason,
            ['punkte' => $warning->points, 'tage' => $days]);
        $consequence = $this->consequence($site, $customer, $by);
        $notify = Notify::make(self::NOTIFICATION)->site($site)->title('Sie wurden verwarnt: :reason', ['reason' => $warning->reason])->to($customer);
        if ($consequence !== null) {
            $notify->body($consequence === 'banned' ? 'Ihr Konto ist für die Community vorübergehend gesperrt.' : 'Sie können vorübergehend nicht schreiben.');
        }
        $notify->send();

        return ['warning' => $warning, 'consequence' => $consequence];
    }

    public function revoke(Site $site, CommunityWarning $warning, Moderator $by): void
    {
        $warning->forceFill(['revoked_at' => now()])->save();
        $customer = Customer::query()->withoutGlobalScopes()->find($warning->customer_id);
        $this->log->record($site, 'member.warning_revoked', $by, 'customer', $warning->customer_id, $customer === null ? null : ($this->sanctions->profile($customer)->name ?? $customer->email),
            $warning->customer_id, $warning->reason, ['punkte' => $warning->points]);
    }

    public function points(Customer $customer): int
    {
        return (int) CommunityWarning::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->whereNull('revoked_at')
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('points');
    }

    /** Die Folge der gültigen Punkte — Sperre vor Schreibsperre; eine schon längere Sperre bleibt. */
    private function consequence(Site $site, Customer $customer, Moderator $by): ?string
    {
        $points = $this->points($customer);
        $values = $this->settings->all($site);
        $profile = $this->sanctions->profile($customer);
        if ($values['ban_points'] > 0 && $points >= $values['ban_points']) {
            $until = now()->addDays(max(1, $values['ban_days']));
            if ($profile === null || ! $profile->isBanned() || ($profile->banned_until !== null && $profile->banned_until->lt($until))) {
                $this->sanctions->ban($site, $customer, $by, $until, __('Automatisch: :points Verwarnungspunkte', ['points' => $points]));
            }

            return 'banned';
        }
        if ($values['mute_points'] > 0 && $points >= $values['mute_points']) {
            $until = now()->addDays(max(1, $values['mute_days']));
            if ($profile === null || ! $profile->isMuted() || ($profile->muted_until !== null && $profile->muted_until->lt($until))) {
                $this->sanctions->mute($site, $customer, $by, $until, __('Automatisch: :points Verwarnungspunkte', ['points' => $points]));
            }

            return 'muted';
        }

        return null;
    }
}
