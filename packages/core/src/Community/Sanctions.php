<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use DateTimeInterface;

/**
 * Sperren in der Community (CR-006 FO-17): **Schreibsperre** (lesen ja; schreiben, reagieren, abstimmen nein) und
 * **Sperre** (die Person zählt wie ein Gast). Beides steht am Community-Profil, mit oder ohne Ende. Die Prüfung lädt
 * das Profil einmal je Anfrage (scoped, Octane-sicher).
 */
final class Sanctions
{
    /** @var array<int, CommunityProfile|null> */
    private array $profiles = [];

    public function __construct(private readonly ModerationLog $log) {}

    public function profile(Customer $customer): ?CommunityProfile
    {
        if (! array_key_exists($customer->id, $this->profiles)) {
            $this->profiles[$customer->id] = CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->first();
        }

        return $this->profiles[$customer->id];
    }

    public function isBanned(?Customer $customer): bool
    {
        return $customer !== null && ($this->profile($customer)?->isBanned() ?? false);
    }

    public function isMuted(?Customer $customer): bool
    {
        return $customer !== null && ($this->profile($customer)?->isMuted() ?? false);
    }

    /** Bis wann die Schreibsperre (oder Sperre) gilt — null heisst ohne Ende. */
    public function until(Customer $customer): ?DateTimeInterface
    {
        $profile = $this->profile($customer);
        if ($profile === null) {
            return null;
        }

        return $profile->isBanned() ? $profile->banned_until : $profile->muted_until;
    }

    public function mute(Site $site, Customer $customer, Moderator $by, ?DateTimeInterface $until, ?string $reason = null): void
    {
        $this->update($customer, ['muted_at' => now(), 'muted_until' => $until]);
        $this->log->record($site, 'member.muted', $by, 'customer', $customer->id, $this->name($customer), $customer->id, $reason, ['bis' => $until?->format(DATE_ATOM)]);
    }

    public function unmute(Site $site, Customer $customer, Moderator $by): void
    {
        $this->update($customer, ['muted_at' => null, 'muted_until' => null]);
        $this->log->record($site, 'member.unmuted', $by, 'customer', $customer->id, $this->name($customer), $customer->id);
    }

    public function ban(Site $site, Customer $customer, Moderator $by, ?DateTimeInterface $until, ?string $reason = null): void
    {
        $this->update($customer, ['banned_at' => now(), 'banned_until' => $until, 'ban_reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500)]);
        $this->log->record($site, 'member.banned', $by, 'customer', $customer->id, $this->name($customer), $customer->id, $reason, ['bis' => $until?->format(DATE_ATOM)]);
    }

    public function unban(Site $site, Customer $customer, Moderator $by): void
    {
        $this->update($customer, ['banned_at' => null, 'banned_until' => null, 'ban_reason' => null]);
        $this->log->record($site, 'member.unbanned', $by, 'customer', $customer->id, $this->name($customer), $customer->id);
    }

    public function forget(): void
    {
        $this->profiles = [];
    }

    /** @param  array<string, mixed>  $values */
    private function update(Customer $customer, array $values): void
    {
        $profile = $this->profile($customer);
        if ($profile === null) {
            // Ohne Profil keine Beiträge — die Sperre braucht trotzdem einen Ort
            $site = Site::query()->findOrFail($customer->site_id);
            $profile = app(Profiles::class)->save($customer, app(Profiles::class)->suggestion($site, 'Mitglied'));
        }
        $profile->forceFill($values)->save();
        $this->profiles[$customer->id] = $profile;
        app(VisitorGroups::class)->forget();
    }

    private function name(Customer $customer): string
    {
        return $this->profile($customer)->name ?? (string) $customer->email;
    }
}
