<?php

declare(strict_types=1);

namespace Basalto\Modules\Members\Privacy;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Modules\Members\Models\Member;

/**
 * Mitgliedschaften im Datenschutz des Kerns (N-05, wie die Kommentare): Auskunft über Site, Stand und Zeitpunkte je
 * E-Mail des Frontend-Kontos; Löschen löscht die Mitgliedschaften. Das Konto selbst anonymisiert danach der Kern.
 */
final class MemberPersonData
{
    /** @return array<string, mixed> */
    public function __invoke(string $email, bool $delete): array
    {
        $customers = Customer::byEmail($email)->pluck('id');
        $members = Member::query()->withoutGlobalScopes()->whereIn('customer_id', $customers)->orderBy('id')->get();
        if ($members->isEmpty()) {
            return [];
        }
        if ($delete) {
            return ['mitgliedschaften' => Member::query()->withoutGlobalScopes()->whereKey($members->modelKeys())->delete()];
        }

        return ['mitgliedschaften' => $members->map(static fn (Member $m): array => ['site' => $m->site_id, 'stand' => $m->status->value,
            'seit' => $m->created_at?->toIso8601String(), 'eingeladen' => $m->invited_at?->toIso8601String(), 'freigegeben' => $m->approved_at?->toIso8601String(),
            'gesperrt' => $m->blocked_at?->toIso8601String()])->all()];
    }
}
